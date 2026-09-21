<?php

namespace App\Services\SocialProviders;

use App\Models\PostVariant;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class FacebookProvider extends AbstractSocialProvider
{
    protected const GRAPH_VERSION = 'v19.0';
    protected const AUTH_URL      = 'https://www.facebook.com/' . self::GRAPH_VERSION . '/dialog/oauth';
    protected const GRAPH_URL     = 'https://graph.facebook.com/' . self::GRAPH_VERSION;

    public function getPlatformIdentifier(): string
    {
        return 'facebook';
    }

    // ──────────────────────────────────────────────────────────────────────
    // OAuth 2.0 Flow
    // ──────────────────────────────────────────────────────────────────────

    public function getAuthorizationUrl(string $state, string $redirectUri): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id'     => config('services.facebook.client_id'),
            'redirect_uri'  => $redirectUri,
            'state'         => $state,
            'scope'         => implode(',', $this->getScopes()),
            'response_type' => 'code',
        ]);
    }

    public function exchangeCodeForTokens(string $code, string $redirectUri): array
    {
        // Step 1: Exchange auth code for short-lived user access token
        $response = Http::get(self::GRAPH_URL . '/oauth/access_token', [
            'client_id'     => config('services.facebook.client_id'),
            'client_secret' => config('services.facebook.client_secret'),
            'redirect_uri'  => $redirectUri,
            'code'          => $code,
        ]);

        if ($response->failed()) {
            Log::error('Facebook code exchange failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            throw new RuntimeException('Failed to exchange Facebook authorization code: ' . $response->json('error.message', 'Unknown error'));
        }

        $shortLivedToken = $response->json('access_token');

        // Step 2: Exchange short-lived token for long-lived (60-day) user token
        $longLivedResponse = Http::get(self::GRAPH_URL . '/oauth/access_token', [
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => config('services.facebook.client_id'),
            'client_secret'     => config('services.facebook.client_secret'),
            'fb_exchange_token' => $shortLivedToken,
        ]);

        if ($longLivedResponse->successful()) {
            $data = $longLivedResponse->json();
            return [
                'access_token' => $data['access_token'],
                'expires_in'   => $data['expires_in'] ?? 5184000, // ~60 days default
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
            throw new RuntimeException('Failed to fetch Facebook user profile: ' . $response->json('error.message', 'Unknown error'));
        }

        $data = $response->json();

        return [
            'id'         => $data['id'],
            'name'       => $data['name'] ?? 'Facebook User',
            'email'      => $data['email'] ?? null,
            'avatar_url' => $data['picture']['data']['url'] ?? null,
        ];
    }

    public function connect(array $credentials): SocialAccount
    {
        $tokens = $this->exchangeCodeForTokens($credentials['code'], $credentials['redirect_uri']);
        $userAccessToken = $tokens['access_token'];

        $userProfile = $this->fetchProfile($userAccessToken);
        $workspace = Workspace::findOrFail($credentials['workspace_id']);

        // Check if user manages Facebook Pages.
        // Posting content via API requires a Page Access Token.
        $pagesResponse = Http::get(self::GRAPH_URL . '/me/accounts', [
            'fields'       => 'id,name,category,access_token,picture{url}',
            'access_token' => $userAccessToken,
        ]);

        $pages = $pagesResponse->successful() ? $pagesResponse->json('data', []) : [];

        if (!empty($pages)) {
            // Use the primary / first Page connected
            $primaryPage = $pages[0];

            $account = $workspace->socialAccounts()->updateOrCreate(
                [
                    'platform'            => 'facebook',
                    'platform_account_id' => $primaryPage['id'],
                ],
                [
                    'name'              => $primaryPage['name'],
                    'username'          => $primaryPage['name'],
                    'account_type'      => 'page',
                    'avatar_url'        => $primaryPage['picture']['data']['url'] ?? $userProfile['avatar_url'],
                    'connection_status' => 'connected',
                ]
            );

            // Page Access Token derived from a long-lived user token never expires
            $account->token()->updateOrCreate(
                ['social_account_id' => $account->id],
                [
                    'access_token'  => $primaryPage['access_token'],
                    'refresh_token' => $userAccessToken, // Keep user token as fallback refresh
                    'expires_at'    => null,
                    'scopes'        => $tokens['scopes'],
                ]
            );

            return $account;
        }

        // Fallback: connect profile account if no Page exists
        $account = $workspace->socialAccounts()->updateOrCreate(
            [
                'platform'            => 'facebook',
                'platform_account_id' => $userProfile['id'],
            ],
            [
                'name'              => $userProfile['name'],
                'username'          => $userProfile['name'],
                'account_type'      => 'profile',
                'avatar_url'        => $userProfile['avatar_url'],
                'connection_status' => 'connected',
            ]
        );

        $account->token()->updateOrCreate(
            ['social_account_id' => $account->id],
            [
                'access_token'  => $userAccessToken,
                'refresh_token' => null,
                'expires_at'    => now()->addSeconds($tokens['expires_in']),
                'scopes'        => $tokens['scopes'],
            ]
        );

        return $account;
    }

    public function refreshToken(SocialAccount $account): bool
    {
        $token = $this->resolveToken($account);

        // Page access tokens do not expire unless user password changes or app permissions are revoked
        if ($account->account_type === 'page' && empty($token->refresh_token)) {
            return true;
        }

        $userToken = $token->refresh_token ?: $token->access_token;

        $response = Http::get(self::GRAPH_URL . '/oauth/access_token', [
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => config('services.facebook.client_id'),
            'client_secret'     => config('services.facebook.client_secret'),
            'fb_exchange_token' => $userToken,
        ]);

        if ($response->failed()) {
            Log::warning('Facebook token refresh failed', [
                'account_id' => $account->id,
                'status'     => $response->status(),
            ]);
            $account->update(['connection_status' => 'expired']);
            return false;
        }

        $data = $response->json();

        $token->update([
            'access_token' => $data['access_token'],
            'expires_at'   => now()->addSeconds($data['expires_in'] ?? 5184000),
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

        if (empty(trim($content)) && !$hasMedia) {
            $errors[] = 'Facebook post must contain text content or attached media.';
        }

        if (mb_strlen($content) > 63206) {
            $errors[] = 'Facebook post content exceeds maximum limit of 63,206 characters.';
        }

        return $errors;
    }

    public function uploadMedia(SocialAccount $account, string $mediaUrl, string $mimeType): array
    {
        $token = $this->ensureValidToken($account);
        if (!$token) {
            return ['success' => false, 'error_code' => 'TOKEN_EXPIRED', 'error_message' => 'Token expired.'];
        }

        $targetId = $account->platform_account_id;
        $content = @file_get_contents($mediaUrl);

        if ($content === false) {
            return ['success' => false, 'error_code' => 'FILE_READ_FAILED', 'error_message' => 'Could not read media file.'];
        }

        if (str_starts_with($mimeType, 'video/')) {
            $res = Http::timeout(300)
                ->attach('source', $content, 'video.mp4')
                ->post(self::GRAPH_URL . "/{$targetId}/videos", [
                    'access_token' => $token->access_token,
                ]);
        } else {
            $res = Http::timeout(60)->attach('source', $content, 'photo.jpg')
                ->post(self::GRAPH_URL . "/{$targetId}/photos", [
                    'published'    => 'false',
                    'temporary'    => 'true',
                    'access_token' => $token->access_token,
                ]);
        }

        if ($res->failed()) {
            return $this->formatErrorResponse($res, 'Facebook media upload failed');
        }

        return ['success' => true, 'media_id' => $res->json('id')];
    }


    // ──────────────────────────────────────────────────────────────────────
    // Publishing Engine
    // ──────────────────────────────────────────────────────────────────────

    public function publishPost(SocialAccount $account, PostVariant $variant): array
    {
        $token = $this->ensureValidToken($account);

        if (!$token) {
            return [
                'success'       => false,
                'error_code'    => 'TOKEN_EXPIRED',
                'error_message' => 'Facebook access token expired and could not be refreshed.',
            ];
        }

        $targetId = $account->platform_account_id;
        $accessToken = $token->access_token;
        $content = $variant->content ?? '';
        $post = $variant->post;
        $metadata = $variant->metadata ?? [];

        $mediaItems = $post?->media ?? collect();
        $videoItem = $mediaItems->first(fn($m) => str_starts_with($m->mime_type, 'video/'));
        $imageItems = $mediaItems->filter(fn($m) => str_starts_with($m->mime_type, 'image/'));

        // Case 1: Video Post
        if ($videoItem) {
            return $this->publishVideo($targetId, $accessToken, $videoItem, $content, $post?->title);
        }

        // Case 2: Multi-Image Post (Album/Carousel feed post)
        if ($imageItems->count() > 1) {
            return $this->publishMultiImage($targetId, $accessToken, $imageItems, $content);
        }

        // Case 3: Single Image Post
        if ($imageItems->count() === 1) {
            return $this->publishSingleImage($targetId, $accessToken, $imageItems->first(), $content);
        }

        // Case 4: Text Post or Link Post
        $params = [
            'message'      => $content,
            'access_token' => $accessToken,
        ];

        if (!empty($metadata['link_url'])) {
            $params['link'] = $metadata['link_url'];
        }

        $response = Http::asForm()->post(self::GRAPH_URL . "/{$targetId}/feed", $params);

        if ($response->failed()) {
            return $this->formatErrorResponse($response, 'Facebook text post failed');
        }

        $postId = $response->json('id');

        return [
            'success'      => true,
            'external_id'  => $postId,
            'external_url' => "https://facebook.com/{$postId}",
            'response'     => $response->json(),
        ];
    }

    protected function publishSingleImage(string $targetId, string $accessToken, $mediaItem, string $message): array
    {
        $binary = $mediaItem->getBinaryContent();

        if ($binary) {
            $response = Http::timeout(60)->attach('source', $binary, $mediaItem->original_name ?: 'photo.jpg')
                ->post(self::GRAPH_URL . "/{$targetId}/photos", [
                    'message'      => $message,
                    'access_token' => $accessToken,
                ]);
        } else {
            $response = Http::timeout(60)->asForm()->post(self::GRAPH_URL . "/{$targetId}/photos", [
                'url'          => $mediaItem->url,
                'message'      => $message,
                'access_token' => $accessToken,
            ]);
        }

        if ($response->failed()) {
            return $this->formatErrorResponse($response, 'Facebook photo publish failed');
        }

        $postId = $response->json('post_id') ?? $response->json('id');

        return [
            'success'      => true,
            'external_id'  => $postId,
            'external_url' => "https://facebook.com/{$postId}",
            'response'     => $response->json(),
        ];
    }

    protected function publishMultiImage(string $targetId, string $accessToken, $imageItems, string $message): array
    {
        $mediaIds = [];

        // Upload each photo unpublished first
        foreach ($imageItems as $img) {
            $binary = $img->getBinaryContent();

            if ($binary) {
                $uploadRes = Http::timeout(60)->attach('source', $binary, $img->original_name ?: 'photo.jpg')
                    ->post(self::GRAPH_URL . "/{$targetId}/photos", [
                        'published'    => 'false',
                        'temporary'    => 'true',
                        'access_token' => $accessToken,
                    ]);
            } else {
                $uploadRes = Http::timeout(60)->asForm()->post(self::GRAPH_URL . "/{$targetId}/photos", [
                    'url'          => $img->url,
                    'published'    => 'false',
                    'temporary'    => 'true',
                    'access_token' => $accessToken,
                ]);
            }

            if ($uploadRes->successful() && $uploadRes->json('id')) {
                $mediaIds[] = ['media_fbid' => $uploadRes->json('id')];
            }
        }

        if (empty($mediaIds)) {
            return [
                'success'       => false,
                'error_code'    => 'MEDIA_UPLOAD_FAILED',
                'error_message' => 'Failed to upload attached photos to Facebook.',
            ];
        }

        // Publish feed post referencing all attached photos
        $feedParams = [
            'message'        => $message,
            'attached_media' => json_encode($mediaIds),
            'access_token'   => $accessToken,
        ];

        $response = Http::asForm()->post(self::GRAPH_URL . "/{$targetId}/feed", $feedParams);

        if ($response->failed()) {
            return $this->formatErrorResponse($response, 'Facebook multi-photo publish failed');
        }

        $postId = $response->json('id');

        return [
            'success'      => true,
            'external_id'  => $postId,
            'external_url' => "https://facebook.com/{$postId}",
            'response'     => $response->json(),
        ];
    }

    protected function publishVideo(string $targetId, string $accessToken, $videoItem, string $description, ?string $title): array
    {
        $binary = $videoItem->getBinaryContent();

        $params = [
            'description'  => $description,
            'title'        => $title ?? 'Video Post',
            'access_token' => $accessToken,
        ];

        if ($binary) {
            $response = Http::timeout(300)
                ->attach('source', $binary, $videoItem->original_name ?: 'video.mp4')
                ->post(self::GRAPH_URL . "/{$targetId}/videos", $params);
        } else {
            $params['file_url'] = $videoItem->url;
            $response = Http::timeout(300)
                ->asForm()
                ->post(self::GRAPH_URL . "/{$targetId}/videos", $params);
        }

        if ($response->failed()) {
            return $this->formatErrorResponse($response, 'Facebook video publish failed');
        }

        $videoId = $response->json('id');

        return [
            'success'      => true,
            'external_id'  => $videoId,
            'external_url' => "https://facebook.com/{$videoId}",
            'response'     => $response->json(),
        ];
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
            ['fields' => 'id,message,created_time,permalink_url']
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
            'metric'       => 'page_impressions,page_engaged_users,page_post_engagements',
            'period'       => 'day',
            'access_token' => $token->access_token,
        ]);

        return $response->successful() ? $response->json() : ['note' => 'Page insights not available'];
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
            'pages_manage_posts',
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
            'error_code'    => $response->json('error.code', 'FB_API_ERROR'),
            'error_message' => $response->json('error.message', 'Facebook API operation failed.'),
            'response'      => $response->json(),
        ];
    }
}
