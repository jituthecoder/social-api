<?php

namespace App\Services\SocialProviders;

use App\Models\PostVariant;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class LinkedInProvider extends AbstractSocialProvider
{
    // ──────────────────────────────────────────────────────────────────────
    // API Endpoints
    // ──────────────────────────────────────────────────────────────────────

    protected const AUTH_URL     = 'https://www.linkedin.com/oauth/v2/authorization';
    protected const TOKEN_URL    = 'https://www.linkedin.com/oauth/v2/accessToken';
    protected const USERINFO_URL = 'https://api.linkedin.com/v2/userinfo';
    protected const POSTS_URL    = 'https://api.linkedin.com/rest/posts';
    protected const IMAGES_URL   = 'https://api.linkedin.com/rest/images?action=initializeUpload';
    protected const VIDEOS_URL   = 'https://api.linkedin.com/rest/videos?action=initializeUpload';
    protected const API_VERSION  = '202608';

    // ──────────────────────────────────────────────────────────────────────
    // SocialProviderInterface
    // ──────────────────────────────────────────────────────────────────────

    public function getPlatformIdentifier(): string
    {
        return 'linkedin';
    }

    // LinkedIn-specific timeout overrides
    protected int $timeoutDefault = 45;   // LinkedIn API is sometimes slow
    protected int $timeoutUpload  = 120;  // Image upload
    protected int $timeoutVideoUp = 300;  // Video upload
    protected int $maxRetries     = 3;

    // ──────────────────────────────────────────────────────────────────────
    // OAuth 2.0
    // ──────────────────────────────────────────────────────────────────────

    public function getAuthorizationUrl(string $state, string $redirectUri): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'response_type' => 'code',
            'client_id'     => config('services.linkedin.client_id'),
            'redirect_uri'  => $redirectUri,
            'state'         => $state,
            'scope'         => implode(' ', $this->getScopes()),
        ]);
    }

    public function exchangeCodeForTokens(string $code, string $redirectUri): array
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
            'client_id'     => config('services.linkedin.client_id'),
            'client_secret' => config('services.linkedin.client_secret'),
        ]);

        if ($response->failed()) {
            Log::error('LinkedIn token exchange failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new RuntimeException('Failed to exchange LinkedIn authorization code for tokens.');
        }

        $data = $response->json();

        return [
            'access_token'  => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? null,
            'expires_in'    => $data['expires_in'] ?? 5184000,
            'scopes'        => $data['scope'] ?? implode(',', $this->getScopes()),
        ];
    }

    public function fetchProfile(string $accessToken): array
    {
        $response = Http::withToken($accessToken)->get(self::USERINFO_URL);

        if ($response->failed()) {
            Log::error('LinkedIn userinfo fetch failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new RuntimeException('Failed to fetch LinkedIn user profile.');
        }

        $data = $response->json();

        return [
            'platform_account_id' => $data['sub'],
            'name'                => $data['name'] ?? trim(($data['given_name'] ?? '') . ' ' . ($data['family_name'] ?? '')),
            'username'            => $data['email'] ?? null,
            'avatar_url'          => $data['picture'] ?? null,
            'account_type'        => 'profile',
        ];
    }

    // ──────────────────────────────────────────────────────────────────────
    // Account Management
    // ──────────────────────────────────────────────────────────────────────

    public function connect(array $credentials): SocialAccount
    {
        $tokens  = $this->exchangeCodeForTokens($credentials['code'], $credentials['redirect_uri']);
        $profile = $this->fetchProfile($tokens['access_token']);

        $account = SocialAccount::updateOrCreate(
            [
                'workspace_id'        => $credentials['workspace_id'],
                'platform'            => $this->getPlatformIdentifier(),
                'platform_account_id' => $profile['platform_account_id'],
            ],
            [
                'name'              => $profile['name'],
                'username'          => $profile['username'],
                'account_type'      => $profile['account_type'],
                'avatar_url'        => $profile['avatar_url'],
                'connection_status' => 'connected',
            ]
        );

        $account->token()->updateOrCreate(
            ['social_account_id' => $account->id],
            [
                'access_token'  => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'expires_at'    => now()->addSeconds($tokens['expires_in']),
                'scopes'        => $tokens['scopes'],
            ]
        );

        return $account;
    }

    public function refreshToken(SocialAccount $account): bool
    {
        $token = $this->resolveToken($account);

        if (!$token->refresh_token) {
            $account->update(['connection_status' => 'expired']);
            return false;
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $token->refresh_token,
            'client_id'     => config('services.linkedin.client_id'),
            'client_secret' => config('services.linkedin.client_secret'),
        ]);

        if ($response->failed()) {
            Log::warning('LinkedIn token refresh failed', [
                'account_id' => $account->id,
                'status'     => $response->status(),
            ]);
            $account->update(['connection_status' => 'expired']);
            return false;
        }

        $data = $response->json();

        $token->update([
            'access_token'  => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $token->refresh_token,
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

        if (empty(trim($content))) {
            $errors[] = 'Post content cannot be empty.';
        }

        if (mb_strlen($content) > 3000) {
            $errors[] = 'LinkedIn post content must not exceed 3,000 characters.';
        }

        return $errors;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Media Upload (Images & Videos)
    // ──────────────────────────────────────────────────────────────────────

    public function uploadMedia(SocialAccount $account, string $mediaUrl, string $mimeType): array
    {
        $content = @file_get_contents($mediaUrl);
        if ($content === false) {
            return ['success' => false, 'error_code' => 'FILE_READ_FAILED', 'error_message' => 'Could not read file.'];
        }

        if (str_starts_with($mimeType, 'video/')) {
            $urn = $this->uploadVideoContent($account, $content, $mimeType);
        } else {
            $urn = $this->uploadImageContent($account, $content, $mimeType);
        }

        if (!$urn) {
            return ['success' => false, 'error_code' => 'UPLOAD_FAILED', 'error_message' => 'Failed to upload media to LinkedIn.'];
        }

        return ['success' => true, 'media_id' => $urn];
    }

    public function uploadImageContent(SocialAccount $account, string $binaryContent, string $mimeType = 'image/jpeg'): ?string
    {
        $token = $this->ensureValidToken($account);
        if (!$token) {
            return null;
        }

        // LinkedIn Images API strictly accepts image/jpeg, image/png, image/gif.
        // Auto-convert any modern formats (like AVIF, WebP, BMP) to JPEG via PHP GD
        $supportedMimes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
        if (!in_array(strtolower($mimeType), $supportedMimes)) {
            $gdImg = @imagecreatefromstring($binaryContent);
            if ($gdImg !== false) {
                ob_start();
                imagejpeg($gdImg, null, 92);
                $binaryContent = ob_get_clean();
                imagedestroy($gdImg);
                $mimeType = 'image/jpeg';
            }
        }

        $personUrn = 'urn:li:person:' . $account->platform_account_id;

        $initResponse = Http::withToken($token->access_token)
            ->withHeaders($this->linkedInHeaders())
            ->post(self::IMAGES_URL, [
                'initializeUploadRequest' => ['owner' => $personUrn],
            ]);

        if ($initResponse->failed()) {
            Log::error('LinkedIn image init failed', ['body' => $initResponse->body()]);
            return null;
        }


        $uploadUrl = $initResponse->json('value.uploadUrl');
        $imageUrn  = $initResponse->json('value.image');

        $uploadResponse = $this->apiUploadBinaryPut($uploadUrl, $binaryContent, $mimeType);

        if ($uploadResponse->failed()) {
            Log::error('LinkedIn image upload failed', ['status' => $uploadResponse->status()]);
            return null;
        }

        return $imageUrn;
    }

    public function uploadVideoContent(SocialAccount $account, string $binaryContent, string $mimeType = 'video/mp4'): ?string
    {
        $token = $this->ensureValidToken($account);
        if (!$token) {
            return null;
        }

        $personUrn = 'urn:li:person:' . $account->platform_account_id;
        $fileSize  = strlen($binaryContent);

        $initResponse = Http::withToken($token->access_token)
            ->withHeaders($this->linkedInHeaders())
            ->post(self::VIDEOS_URL, [
                'initializeUploadRequest' => [
                    'owner'           => $personUrn,
                    'fileSizeBytes'   => $fileSize,
                    'uploadCaptions'  => false,
                    'uploadThumbnail' => false,
                ],
            ]);

        if ($initResponse->failed()) {
            Log::error('LinkedIn video init failed', ['body' => $initResponse->body()]);
            return null;
        }

        $uploadInstructions = $initResponse->json('value.uploadInstructions.0');
        $uploadUrl          = $uploadInstructions['uploadUrl'] ?? null;
        $videoUrn           = $initResponse->json('value.video');

        if (!$uploadUrl || !$videoUrn) {
            return null;
        }

        $uploadResponse = $this->apiUploadVideo($uploadUrl, $binaryContent, $mimeType, $fileSize);

        if ($uploadResponse->failed()) {
            Log::error('LinkedIn video upload failed', ['status' => $uploadResponse->status()]);
            return null;
        }

        return $videoUrn;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Publish / Delete / Get Post
    // ──────────────────────────────────────────────────────────────────────

    public function publishPost(SocialAccount $account, PostVariant $variant): array
    {
        $token = $this->ensureValidToken($account);

        if (!$token) {
            return [
                'success'       => false,
                'error_code'    => 'TOKEN_EXPIRED',
                'error_message' => 'LinkedIn access token expired and could not be refreshed.',
            ];
        }

        $personUrn = 'urn:li:person:' . $account->platform_account_id;

        $postBody = [
            'author'        => $personUrn,
            'lifecycleState' => 'PUBLISHED',
            'visibility'    => 'PUBLIC',
            'commentary'    => $variant->content,
            'distribution'  => [
                'feedDistribution'               => 'MAIN_FEED',
                'targetEntities'                 => [],
                'thirdPartyDistributionChannels' => [],
            ],
        ];

        // Attach media (Images or Videos)
        $metadata = $variant->metadata ?? [];
        $post = $variant->post;

        // Check attached media from relational Post or variant metadata
        $mediaItems = $post?->media ?? collect();

        $videoItem = $mediaItems->first(fn($m) => str_starts_with($m->mime_type, 'video/'));
        $imageItems = $mediaItems->filter(fn($m) => str_starts_with($m->mime_type, 'image/'));

        if ($videoItem) {
            $binary = $videoItem->getBinaryContent();
            if ($binary) {
                $videoUrn = $this->uploadVideoContent($account, $binary, $videoItem->mime_type);
                if ($videoUrn) {
                    $postBody['content'] = [
                        'media' => [
                            'id'    => $videoUrn,
                            'title' => $post?->title ?? 'Video Post',
                        ],
                    ];
                }
            }
        } elseif ($imageItems->count() === 1) {
            $img = $imageItems->first();
            $binary = $img->getBinaryContent();
            if ($binary) {
                $imgUrn = $this->uploadImageContent($account, $binary, $img->mime_type);
                if ($imgUrn) {
                    $postBody['content'] = [
                        'media' => ['id' => $imgUrn],
                    ];
                }
            }
        } elseif ($imageItems->count() > 1) {
            $uploadedUrns = [];
            foreach ($imageItems as $img) {
                $binary = $img->getBinaryContent();
                if ($binary) {
                    $urn = $this->uploadImageContent($account, $binary, $img->mime_type);
                    if ($urn) {
                        $uploadedUrns[] = ['id' => $urn];
                    }
                }
            }
            if (!empty($uploadedUrns)) {
                $postBody['content'] = [
                    'multiImage' => ['images' => $uploadedUrns],
                ];
            }
        } elseif (!empty($metadata['link_url'])) {
            $postBody['content'] = [
                'article' => [
                    'source'      => $metadata['link_url'],
                    'title'       => $metadata['link_title'] ?? $post?->title ?? 'Link Preview',
                    'description' => $metadata['link_description'] ?? '',
                ],
            ];
        }

        $response = $this->apiPost(self::POSTS_URL, $token->access_token, $postBody, $this->linkedInHeaders());

        if ($response->failed()) {
            Log::error('LinkedIn publish failed', [
                'account_id' => $account->id,
                'variant_id' => $variant->id,
                'status'     => $response->status(),
                'body'       => mb_substr($response->body(), 0, 500),
            ]);

            return [
                'success'       => false,
                'error_code'    => 'PUBLISH_FAILED',
                'error_message' => $response->json('message', 'LinkedIn publishing failed.'),
                'response'      => $response->json(),
            ];
        }

        $externalId = $response->header('x-restli-id') ?? $response->json('id');

        return [
            'success'     => true,
            'external_id' => $externalId,
            'response'    => $response->json(),
        ];
    }

    public function deletePost(SocialAccount $account, string $externalPostId): bool
    {
        $token = $this->ensureValidToken($account);

        if (!$token) {
            return false;
        }

        $response = $this->apiDelete(self::POSTS_URL . '/' . urlencode($externalPostId), $token->access_token, $this->linkedInHeaders());

        return $response->successful();
    }

    public function getPost(SocialAccount $account, string $externalPostId): array
    {
        $token = $this->ensureValidToken($account);

        if (!$token) {
            return ['success' => false, 'error_message' => 'Token expired.'];
        }

        return $this->authenticatedGet(
            self::POSTS_URL . '/' . urlencode($externalPostId),
            $token->access_token,
            [],
            $this->linkedInHeaders()
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Analytics
    // ──────────────────────────────────────────────────────────────────────

    public function getAnalytics(SocialAccount $account, array $params = []): array
    {
        // LinkedIn analytics require Community Management API partner approval.
        // This is a placeholder until Marketing API access is granted.
        return [
            'platform' => $this->getPlatformIdentifier(),
            'note'     => 'LinkedIn analytics require Marketing API partner approval.',
        ];
    }

    // ──────────────────────────────────────────────────────────────────────
    // Internal helpers
    // ──────────────────────────────────────────────────────────────────────

    protected function getScopes(): array
    {
        return ['openid', 'profile', 'email', 'w_member_social'];
    }

    /**
     * Standard LinkedIn REST API headers.
     */
    protected function linkedInHeaders(): array
    {
        return [
            'X-Restli-Protocol-Version' => '2.0.0',
            'LinkedIn-Version'          => self::API_VERSION,
        ];
    }
}
