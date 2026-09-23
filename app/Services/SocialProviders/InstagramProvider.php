<?php

namespace App\Services\SocialProviders;

use App\Models\PostVariant;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class InstagramProvider extends AbstractSocialProvider
{
    protected const GRAPH_VERSION = 'v19.0';
    protected const AUTH_URL      = 'https://www.facebook.com/' . self::GRAPH_VERSION . '/dialog/oauth';
    protected const GRAPH_URL     = 'https://graph.facebook.com/' . self::GRAPH_VERSION;

    public function getPlatformIdentifier(): string
    {
        return 'instagram';
    }

    // ──────────────────────────────────────────────────────────────────────
    // OAuth 2.0 Flow
    // ──────────────────────────────────────────────────────────────────────

    public function getAuthorizationUrl(string $state, string $redirectUri): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id'     => config('services.instagram.client_id', config('services.facebook.client_id')),
            'redirect_uri'  => $redirectUri,
            'state'         => $state,
            'scope'         => implode(',', $this->getScopes()),
            'response_type' => 'code',
        ]);
    }

    public function exchangeCodeForTokens(string $code, string $redirectUri): array
    {
        $clientId     = config('services.instagram.client_id', config('services.facebook.client_id'));
        $clientSecret = config('services.instagram.client_secret', config('services.facebook.client_secret'));

        // Step 1: Exchange auth code for short-lived user token
        $response = Http::get(self::GRAPH_URL . '/oauth/access_token', [
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri'  => $redirectUri,
            'code'          => $code,
        ]);

        if ($response->failed()) {
            Log::error('Instagram code exchange failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            throw new RuntimeException('Failed to exchange Instagram authorization code: ' . $response->json('error.message', 'Unknown error'));
        }

        $shortLivedToken = $response->json('access_token');

        // Step 2: Exchange for long-lived user token (~60 days)
        $longLivedResponse = Http::get(self::GRAPH_URL . '/oauth/access_token', [
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => $clientId,
            'client_secret'     => $clientSecret,
            'fb_exchange_token' => $shortLivedToken,
        ]);

        if ($longLivedResponse->successful()) {
            $data = $longLivedResponse->json();
            return [
                'access_token' => $data['access_token'],
                'expires_in'   => $data['expires_in'] ?? 5184000,
                'scopes'       => $this->getScopes(),
            ];
        }

        return [
            'access_token' => $shortLivedToken,
            'expires_in'   => $response->json('expires_in', 5184000),
            'scopes'       => $this->getScopes(),
        ];
    }

    public function fetchProfile(string $accessToken): array
    {
        $response = Http::get(self::GRAPH_URL . '/me', [
            'fields'       => 'id,name,email,picture.type(large)',
            'access_token' => $accessToken,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Failed to fetch profile: ' . $response->json('error.message', 'Unknown error'));
        }

        return $response->json();
    }

    public function connect(array $credentials): SocialAccount
    {
        $tokens = $this->exchangeCodeForTokens($credentials['code'], $credentials['redirect_uri']);
        $userAccessToken = $tokens['access_token'];
        $workspace = Workspace::findOrFail($credentials['workspace_id']);

        // Query user Facebook Pages to discover linked Instagram Business Accounts
        $pagesResponse = Http::get(self::GRAPH_URL . '/me/accounts', [
            'fields'       => 'id,name,access_token,instagram_business_account{id,username,name,profile_picture_url}',
            'access_token' => $userAccessToken,
        ]);

        if ($pagesResponse->failed()) {
            throw new RuntimeException('Failed to query connected accounts: ' . $pagesResponse->json('error.message', 'Unknown error'));
        }

        $pages = $pagesResponse->json('data', []);
        $linkedIgAccount = null;
        $pageAccessToken = null;

        foreach ($pages as $page) {
            if (!empty($page['instagram_business_account']['id'])) {
                $linkedIgAccount = $page['instagram_business_account'];
                $pageAccessToken = $page['access_token'];
                break;
            }
        }

        if (!$linkedIgAccount) {
            throw new RuntimeException(
                'No Instagram Professional/Business account was found linked to your Facebook Pages. ' .
                'Please switch your Instagram account to Professional/Business in the Instagram app and connect it to your Facebook Page in Page Settings -> Linked Accounts.'
            );
        }

        $account = $workspace->socialAccounts()->updateOrCreate(
            [
                'platform'            => 'instagram',
                'platform_account_id' => $linkedIgAccount['id'],
            ],
            [
                'name'              => $linkedIgAccount['name'] ?? $linkedIgAccount['username'] ?? 'Instagram Account',
                'username'          => $linkedIgAccount['username'] ?? null,
                'account_type'      => 'business',
                'avatar_url'        => $linkedIgAccount['profile_picture_url'] ?? null,
                'connection_status' => 'connected',
            ]
        );

        // Store the Page access token (which has Instagram publishing authorization and no expiry)
        $account->token()->updateOrCreate(
            ['social_account_id' => $account->id],
            [
                'access_token'  => $pageAccessToken,
                'refresh_token' => $userAccessToken,
                'expires_at'    => null,
                'scopes'        => $tokens['scopes'],
            ]
        );

        return $account;
    }

    public function refreshToken(SocialAccount $account): bool
    {
        $token = $this->resolveToken($account);

        if (empty($token->refresh_token)) {
            return true;
        }

        $clientId     = config('services.instagram.client_id', config('services.facebook.client_id'));
        $clientSecret = config('services.instagram.client_secret', config('services.facebook.client_secret'));

        $response = Http::get(self::GRAPH_URL . '/oauth/access_token', [
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => $clientId,
            'client_secret'     => $clientSecret,
            'fb_exchange_token' => $token->refresh_token,
        ]);

        if ($response->failed()) {
            Log::warning('Instagram token refresh failed', [
                'account_id' => $account->id,
                'status'     => $response->status(),
            ]);
            $account->update(['connection_status' => 'expired']);
            return false;
        }

        $data = $response->json();

        $token->update([
            'access_token'  => $data['access_token'],
            'expires_at'    => now()->addSeconds($data['expires_in'] ?? 5184000),
        ]);

        $account->update(['connection_status' => 'connected']);

        return true;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Post Validation
    // ──────────────────────────────────────────────────────────────────────

    public function validatePost(PostVariant $variant): array
    {
        $errors  = [];
        $content = $variant->content ?? '';
        $post    = $variant->post;

        $hasMedia = $post && $post->media()->exists();

        // Instagram strict requirement: text-only posts are not permitted
        if (!$hasMedia) {
            $errors[] = 'Instagram requires at least one image or video attachment. Text-only posts are not supported.';
        }

        if (mb_strlen($content) > 2200) {
            $errors[] = 'Instagram caption exceeds the maximum limit of 2,200 characters.';
        }

        // Check hashtags count (Instagram max 30)
        preg_match_all('/#(\w+)/u', $content, $matches);
        if (count($matches[0] ?? []) > 30) {
            $errors[] = 'Instagram posts cannot exceed 30 hashtags.';
        }

        return $errors;
    }

    public function uploadMedia(SocialAccount $account, string $mediaUrl, string $mimeType): array
    {
        $token = $this->ensureValidToken($account);
        if (!$token) {
            return ['success' => false, 'error_code' => 'TOKEN_EXPIRED', 'error_message' => 'Token expired.'];
        }

        $igUserId = $account->platform_account_id;
        $mediaUrl = $this->ensurePublicUrl($mediaUrl);

        if (str_starts_with($mimeType, 'video/')) {
            $containerRes = Http::asForm()->post(self::GRAPH_URL . "/{$igUserId}/media", [
                'media_type'   => 'REELS',
                'video_url'    => $mediaUrl,
                'access_token' => $token->access_token,
            ]);
        } else {
            $containerRes = Http::asForm()->post(self::GRAPH_URL . "/{$igUserId}/media", [
                'image_url'    => $mediaUrl,
                'access_token' => $token->access_token,
            ]);
        }

        if ($containerRes->failed()) {
            return $this->formatErrorResponse($containerRes, 'Instagram media container upload failed');
        }

        return ['success' => true, 'media_id' => $containerRes->json('id')];
    }


    // ──────────────────────────────────────────────────────────────────────
    // Publishing Engine (Container-based 2-step flow)
    // ──────────────────────────────────────────────────────────────────────

    public function publishPost(SocialAccount $account, PostVariant $variant): array
    {
        $token = $this->ensureValidToken($account);

        if (!$token) {
            return [
                'success'       => false,
                'error_code'    => 'TOKEN_EXPIRED',
                'error_message' => 'Instagram access token expired and could not be refreshed.',
            ];
        }

        $igUserId = $account->platform_account_id;
        $accessToken = $token->access_token;
        $caption = $variant->content ?? '';
        $post = $variant->post;

        $mediaItems = $post?->media ?? collect();
        $videoItem = $mediaItems->first(fn($m) => str_starts_with($m->mime_type, 'video/'));
        $imageItems = $mediaItems->filter(fn($m) => str_starts_with($m->mime_type, 'image/'));

        if ($videoItem) {
            return $this->publishVideoContainer($igUserId, $accessToken, $videoItem, $caption);
        }

        if ($imageItems->count() > 1) {
            return $this->publishCarouselContainer($igUserId, $accessToken, $imageItems, $caption);
        }

        if ($imageItems->count() === 1) {
            return $this->publishSingleImageContainer($igUserId, $accessToken, $imageItems->first(), $caption);
        }

        return [
            'success'       => false,
            'error_code'    => 'MEDIA_REQUIRED',
            'error_message' => 'Instagram requires at least one image or video file to publish.',
        ];
    }

    protected function publishSingleImageContainer(string $igUserId, string $accessToken, $mediaItem, string $caption): array
    {
        $publicUrl = $this->resolvePublicMediaUrl($mediaItem);

        // Step 1: Create Image Container
        $containerResponse = Http::timeout(60)->asForm()->post(self::GRAPH_URL . "/{$igUserId}/media", [
            'image_url'    => $publicUrl,
            'caption'      => $caption,
            'access_token' => $accessToken,
        ]);

        if ($containerResponse->failed()) {
            return $this->formatErrorResponse($containerResponse, 'Instagram image container creation failed');
        }

        $creationId = $containerResponse->json('id');

        // Step 2: Ensure Instagram servers have downloaded and processed the image container
        $this->waitForContainerStatus($creationId, $accessToken, 8);

        // Step 3: Publish Container
        return $this->publishContainer($igUserId, $accessToken, $creationId);
    }

    protected function publishCarouselContainer(string $igUserId, string $accessToken, $imageItems, string $caption): array
    {
        $childContainerIds = [];

        // Step 1: Create child item containers
        foreach ($imageItems as $item) {
            $publicUrl = $this->resolvePublicMediaUrl($item);

            $childRes = Http::timeout(60)->asForm()->post(self::GRAPH_URL . "/{$igUserId}/media", [
                'image_url'        => $publicUrl,
                'is_carousel_item' => 'true',
                'access_token'     => $accessToken,
            ]);

            if ($childRes->successful() && $childRes->json('id')) {
                $childContainerIds[] = $childRes->json('id');
            }
        }

        if (count($childContainerIds) < 2) {
            return [
                'success'       => false,
                'error_code'    => 'CAROUSEL_CREATION_FAILED',
                'error_message' => 'Instagram carousel requires at least 2 valid media items.',
            ];
        }

        // Step 2: Create parent carousel container
        $carouselRes = Http::asForm()->post(self::GRAPH_URL . "/{$igUserId}/media", [
            'media_type'   => 'CAROUSEL',
            'children'     => implode(',', $childContainerIds),
            'caption'      => $caption,
            'access_token' => $accessToken,
        ]);

        if ($carouselRes->failed()) {
            return $this->formatErrorResponse($carouselRes, 'Instagram carousel container creation failed');
        }

        $creationId = $carouselRes->json('id');

        // Step 3: Publish Container
        return $this->publishContainer($igUserId, $accessToken, $creationId);
    }

    protected function publishVideoContainer(string $igUserId, string $accessToken, $videoItem, string $caption): array
    {
        $publicUrl = $this->resolvePublicMediaUrl($videoItem);

        // Step 1: Create Video/Reels Container
        $containerResponse = Http::timeout(60)->asForm()->post(self::GRAPH_URL . "/{$igUserId}/media", [
            'media_type'   => 'REELS',
            'video_url'    => $publicUrl,
            'caption'      => $caption,
            'access_token' => $accessToken,
        ]);

        if ($containerResponse->failed()) {
            return $this->formatErrorResponse($containerResponse, 'Instagram video container creation failed');
        }

        $creationId = $containerResponse->json('id');

        // Step 2: Poll status until FINISHED
        $isReady = $this->waitForContainerStatus($creationId, $accessToken);
        if (!$isReady) {
            return [
                'success'       => false,
                'error_code'    => 'PROCESSING_TIMEOUT',
                'error_message' => 'Instagram video processing timed out or failed.',
            ];
        }

        // Step 3: Publish Container
        return $this->publishContainer($igUserId, $accessToken, $creationId);
    }

    protected function waitForContainerStatus(string $creationId, string $accessToken, int $maxRetries = 15): bool
    {
        for ($i = 0; $i < $maxRetries; $i++) {
            sleep(3);

            $response = Http::get(self::GRAPH_URL . "/{$creationId}", [
                'fields'       => 'status_code',
                'access_token' => $accessToken,
            ]);

            if ($response->successful()) {
                $status = $response->json('status_code');
                if ($status === 'FINISHED') {
                    return true;
                }
                if ($status === 'ERROR' || $status === 'EXPIRED') {
                    Log::error("Instagram container failed with status: {$status}");
                    return false;
                }
            }
        }

        return false;
    }

    protected function publishContainer(string $igUserId, string $accessToken, string $creationId): array
    {
        $publishResponse = null;

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $publishResponse = Http::asForm()->post(self::GRAPH_URL . "/{$igUserId}/media_publish", [
                'creation_id'  => $creationId,
                'access_token' => $accessToken,
            ]);

            if ($publishResponse->successful()) {
                break;
            }

            $errorCode    = (int) $publishResponse->json('error.code');
            $errorSubcode = (int) $publishResponse->json('error.error_subcode');

            // 9007 / 2207027: Media is still downloading or processing on Instagram servers, retry
            if (($errorCode === 9007 || $errorSubcode === 2207027) && $attempt < 3) {
                sleep(3);
                continue;
            }

            break;
        }

        if (!$publishResponse || $publishResponse->failed()) {
            return $this->formatErrorResponse($publishResponse, 'Instagram media publish failed');
        }

        $igMediaId = $publishResponse->json('id');

        // Fetch live permalink (wait brief moment if needed for Instagram to generate shortcode)
        $permalink = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            if ($attempt > 0) {
                usleep(500000); // 0.5s pause
            }
            $mediaInfoRes = Http::get(self::GRAPH_URL . "/{$igMediaId}", [
                'fields'       => 'id,permalink,shortcode',
                'access_token' => $accessToken,
            ]);

            if ($mediaInfoRes->successful()) {
                $permalink = $mediaInfoRes->json('permalink');
                if (!$permalink && $mediaInfoRes->json('shortcode')) {
                    $permalink = "https://www.instagram.com/p/" . $mediaInfoRes->json('shortcode') . "/";
                }
                if ($permalink) {
                    break;
                }
            }
        }

        return [
            'success'      => true,
            'external_id'  => $igMediaId,
            'external_url' => $permalink ?: "https://www.instagram.com",
            'response'     => $publishResponse->json(),
        ];
    }

    public function ensurePublicUrl(string $url): string
    {
        // Determine public base URL from ngrok/redirect URI or app.url
        $redirectUri = config('services.instagram.redirect_uri', config('services.facebook.redirect_uri'));
        $publicHost = null;
        if (!empty($redirectUri)) {
            $parsed = parse_url($redirectUri);
            if (!empty($parsed['scheme']) && !empty($parsed['host'])) {
                $publicHost = $parsed['scheme'] . '://' . $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
            }
        }

        if (!$publicHost) {
            $appUrl = config('app.url', 'http://localhost:8000');
            if (!str_contains($appUrl, 'localhost') && !str_contains($appUrl, '127.0.0.1')) {
                $publicHost = rtrim($appUrl, '/');
            }
        }

        // If URL points to localhost or 127.0.0.1, rewrite with publicHost so Instagram servers can download it
        if ($publicHost && (str_contains($url, 'localhost') || str_contains($url, '127.0.0.1'))) {
            $url = preg_replace('#^https?://(localhost|127\.0\.0\.1)(:\d+)?#', $publicHost, $url);
        } elseif (str_starts_with($url, '/')) {
            $base = $publicHost ?: rtrim(config('app.url', 'http://localhost:8000'), '/');
            $url = $base . $url;
        }

        return $url;
    }

    protected function resolvePublicMediaUrl($mediaItem): string
    {
        $url = $mediaItem->url;

        if (function_exists('imagecreatefromstring')) {
            $localPath = $mediaItem->getLocalFilePath();

            if ($localPath && file_exists($localPath)) {
                $ext = strtolower(pathinfo($localPath, PATHINFO_EXTENSION));

                // Always work with a JPG for Instagram
                $jpgLocalPath = preg_replace('/\.[^.]+$/', '_ig.jpg', $localPath);

                $needsConvert   = ($ext !== 'jpg' && $ext !== 'jpeg');
                $jpgExists      = file_exists($jpgLocalPath);

                // Load image into GD resource
                $im = null;
                if (!$jpgExists) {
                    $raw = @file_get_contents($localPath);
                    if ($raw) {
                        $im = @imagecreatefromstring($raw);
                    }
                }

                if ($im || $jpgExists) {
                    if (!$jpgExists && $im) {
                        $srcW = imagesx($im);
                        $srcH = imagesy($im);

                        // ── Aspect Ratio Check ────────────────────────────────────
                        // Instagram allows: min 0.8 (4:5 portrait) – max 1.91 (landscape)
                        // Safest: crop to 1:1 square if outside bounds
                        $ratio = $srcH > 0 ? ($srcW / $srcH) : 1;

                        $cropW = $srcW;
                        $cropH = $srcH;
                        $cropX = 0;
                        $cropY = 0;

                        if ($ratio < 0.8) {
                            // Too tall (portrait beyond 4:5) → crop height to match 4:5
                            $cropH = (int) round($srcW / 0.8);
                            $cropY = (int) round(($srcH - $cropH) / 2);
                        } elseif ($ratio > 1.91) {
                            // Too wide (landscape beyond 1.91:1) → crop width to 1.91:1
                            $cropW = (int) round($srcH * 1.91);
                            $cropX = (int) round(($srcW - $cropW) / 2);
                        }
                        // else: ratio is already within Instagram's valid range — no crop needed

                        $canvas = imagecreatetruecolor($cropW, $cropH);
                        $white  = imagecolorallocate($canvas, 255, 255, 255);
                        imagefill($canvas, 0, 0, $white);
                        imagecopy($canvas, $im, 0, 0, $cropX, $cropY, $cropW, $cropH);
                        imagejpeg($canvas, $jpgLocalPath, 90);
                        imagedestroy($im);
                        imagedestroy($canvas);

                        Log::info('Instagram image prepared', [
                            'original' => "{$srcW}x{$srcH} ratio=" . round($ratio, 3),
                            'cropped'  => "{$cropW}x{$cropH}",
                            'path'     => $jpgLocalPath,
                        ]);
                    }

                    if (file_exists($jpgLocalPath)) {
                        $disk = $mediaItem->metadata['disk'] ?? config('filesystems.default', 'public');
                        if ($disk !== 'public' && !empty($mediaItem->path)) {
                            $cloudJpgPath = preg_replace('/\.[^.]+$/', '_ig.jpg', $mediaItem->path);
                            try {
                                \Illuminate\Support\Facades\Storage::disk($disk)->put(
                                    $cloudJpgPath,
                                    file_get_contents($jpgLocalPath),
                                    'public'
                                );
                                $url = \Illuminate\Support\Facades\Storage::disk($disk)->url($cloudJpgPath);
                            } catch (\Throwable $e) {
                                Log::warning("Could not upload prepared Instagram image to cloud disk [{$disk}]: " . $e->getMessage());
                                $url = preg_replace('/\.[^.]+$/', '_ig.jpg', $url);
                            }
                        } else {
                            $url = preg_replace('/\.[^.]+$/', '_ig.jpg', $url);
                        }
                    }
                }
            }
        }

        return $this->ensurePublicUrl($url);
    }


    // ──────────────────────────────────────────────────────────────────────
    // Post Management
    // ──────────────────────────────────────────────────────────────────────

    public function deletePost(SocialAccount $account, string $externalPostId): bool
    {
        $token = $this->ensureValidToken($account);
        if (!$token) {
            return false;
        }

        $response = Http::delete(self::GRAPH_URL . "/{$externalPostId}", [
            'access_token' => $token->access_token,
        ]);

        return $response->successful();
    }

    public function getPost(SocialAccount $account, string $externalPostId): array
    {
        $token = $this->ensureValidToken($account);
        if (!$token) {
            return ['success' => false, 'error_message' => 'Token expired.'];
        }

        return $this->authenticatedGet(
            self::GRAPH_URL . "/{$externalPostId}",
            $token->access_token,
            ['fields' => 'id,caption,media_type,media_url,permalink,timestamp']
        );
    }

    public function getAnalytics(SocialAccount $account, array $params = []): array
    {
        $token = $this->ensureValidToken($account);
        if (!$token) {
            return ['success' => false, 'error_message' => 'Token expired.'];
        }

        $targetId = $account->platform_account_id;

        $response = Http::get(self::GRAPH_URL . "/{$targetId}/insights", [
            'metric'       => 'impressions,reach,profile_views',
            'period'       => 'day',
            'access_token' => $token->access_token,
        ]);

        return $response->successful() ? $response->json() : ['note' => 'Instagram insights not available'];
    }

    // ──────────────────────────────────────────────────────────────────────
    // Internal Helpers
    // ──────────────────────────────────────────────────────────────────────

    protected function getScopes(): array
    {
        return [
            'public_profile',
            'email',
            'pages_show_list',
            'pages_read_engagement',
            'instagram_basic',
            'instagram_content_publish',
        ];
    }

    protected function formatErrorResponse($response, string $context): array
    {
        Log::error($context, [
            'status' => $response->status(),
            'body'   => $response->body(),
        ]);

        return [
            'success'       => false,
            'error_code'    => $response->json('error.code', 'IG_API_ERROR'),
            'error_message' => $response->json('error.message', 'Instagram API operation failed.'),
            'response'      => $response->json(),
        ];
    }
}
