<?php

namespace App\Services\SocialProviders;

use App\Models\PostVariant;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class YouTubeProvider extends AbstractSocialProvider
{
    // ──────────────────────────────────────────────────────────────────────
    // API Endpoints
    // ──────────────────────────────────────────────────────────────────────

    protected const AUTH_URL       = 'https://accounts.google.com/o/oauth2/v2/auth';
    protected const TOKEN_URL      = 'https://oauth2.googleapis.com/token';
    protected const CHANNELS_URL   = 'https://www.googleapis.com/youtube/v3/channels';
    protected const VIDEOS_URL     = 'https://www.googleapis.com/upload/youtube/v3/videos';
    protected const VIDEOS_API_URL = 'https://www.googleapis.com/youtube/v3/videos';

    // ──────────────────────────────────────────────────────────────────────
    // SocialProviderInterface
    // ──────────────────────────────────────────────────────────────────────

    public function getPlatformIdentifier(): string
    {
        return 'youtube';
    }

    // ──────────────────────────────────────────────────────────────────────
    // OAuth 2.0 (Google)
    // ──────────────────────────────────────────────────────────────────────

    public function getAuthorizationUrl(string $state, string $redirectUri): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'response_type' => 'code',
            'client_id'     => config('services.youtube.client_id'),
            'redirect_uri'  => $redirectUri,
            'state'         => $state,
            'scope'         => implode(' ', $this->getScopes()),
            'access_type'   => 'offline',   // Required to obtain refresh_token
            'prompt'        => 'consent',   // Force consent screen to always get refresh_token
        ]);
    }

    public function exchangeCodeForTokens(string $code, string $redirectUri): array
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
            'client_id'     => config('services.youtube.client_id'),
            'client_secret' => config('services.youtube.client_secret'),
        ]);

        if ($response->failed()) {
            Log::error('YouTube token exchange failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new RuntimeException('Failed to exchange YouTube authorization code.');
        }

        $data = $response->json();

        return [
            'access_token'  => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? null,
            'expires_in'    => $data['expires_in'] ?? 3600,
            'scopes'        => $data['scope'] ?? implode(',', $this->getScopes()),
        ];
    }

    public function fetchProfile(string $accessToken): array
    {
        $response = Http::withToken($accessToken)->get(self::CHANNELS_URL, [
            'part' => 'snippet,statistics',
            'mine' => 'true',
        ]);

        if ($response->failed()) {
            Log::error('YouTube channel fetch failed', ['body' => $response->body()]);
            throw new RuntimeException('Failed to fetch YouTube channel information.');
        }

        $channels = $response->json('items', []);

        if (empty($channels)) {
            throw new RuntimeException('No YouTube channel found for this Google account.');
        }

        $channel = $channels[0];
        $snippet = $channel['snippet'] ?? [];

        return [
            'platform_account_id' => $channel['id'],
            'name'                => $snippet['title'] ?? 'YouTube Channel',
            'username'            => $snippet['customUrl'] ?? null,
            'avatar_url'          => $snippet['thumbnails']['default']['url'] ?? null,
            'account_type'        => 'channel',
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
            'client_id'     => config('services.youtube.client_id'),
            'client_secret' => config('services.youtube.client_secret'),
        ]);

        if ($response->failed()) {
            Log::warning('YouTube token refresh failed', [
                'account_id' => $account->id,
                'status'     => $response->status(),
            ]);
            $account->update(['connection_status' => 'expired']);
            return false;
        }

        $data = $response->json();

        $token->update([
            'access_token'  => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $token->refresh_token, // Google may not return a new refresh_token
            'expires_at'    => now()->addSeconds($data['expires_in'] ?? 3600),
        ]);

        $account->update(['connection_status' => 'connected']);

        return true;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Post Validation
    // ──────────────────────────────────────────────────────────────────────

    public function validatePost(PostVariant $variant): array
    {
        $errors   = [];
        $metadata = $variant->metadata ?? [];

        if (empty($metadata['video_url']) && empty($metadata['media_url'])) {
            $errors[] = 'YouTube publishing requires a video file.';
        }

        $content = $variant->content ?? '';
        if (mb_strlen($content) > 5000) {
            $errors[] = 'YouTube description must not exceed 5,000 characters.';
        }

        $post = $variant->post;
        if ($post && mb_strlen($post->title ?? '') > 100) {
            $errors[] = 'YouTube video title must not exceed 100 characters.';
        }

        return $errors;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Media Upload
    // ──────────────────────────────────────────────────────────────────────

    public function uploadMedia(SocialAccount $account, string $mediaUrl, string $mimeType): array
    {
        // YouTube media upload is integrated into the publishPost flow
        // via the resumable upload protocol. This method returns a pass-through
        // reference so the publishing pipeline can proceed.
        return [
            'success'   => true,
            'media_url' => $mediaUrl,
            'note'      => 'YouTube video is uploaded as part of the publish flow.',
        ];
    }

    // ──────────────────────────────────────────────────────────────────────
    // Publish / Delete / Get Post (Video)
    // ──────────────────────────────────────────────────────────────────────

    public function publishPost(SocialAccount $account, PostVariant $variant): array
    {
        $token = $this->ensureValidToken($account);

        if (!$token) {
            return [
                'success'       => false,
                'error_code'    => 'TOKEN_EXPIRED',
                'error_message' => 'YouTube access token expired and could not be refreshed.',
            ];
        }

        $metadata = $variant->metadata ?? [];
        $post     = $variant->post;
        $videoUrl = $metadata['video_url'] ?? $metadata['media_url'] ?? null;

        $videoContent = null;
        $mimeType     = $metadata['mime_type'] ?? 'video/mp4';

        if ($videoUrl) {
            $videoContent = @file_get_contents($videoUrl);
        } elseif ($post && $post->media) {
            $videoMedia = $post->media->first(fn($m) => str_starts_with($m->mime_type, 'video/'));
            if ($videoMedia) {
                $videoContent = $videoMedia->getBinaryContent();
                $mimeType     = $videoMedia->mime_type;
            }
        }

        if (!$videoContent) {
            return [
                'success'       => false,
                'error_code'    => 'NO_VIDEO',
                'error_message' => 'YouTube publishing requires a video file.',
            ];
        }

        $fileSize = strlen($videoContent);

        // Step 1: Initialize resumable upload session
        $videoMetadata = [
            'snippet' => [
                'title'       => $metadata['title'] ?? $post?->title ?? 'Untitled Video',
                'description' => $variant->content ?? '',
                'tags'        => $variant->hashtags ?? $metadata['tags'] ?? [],
                'categoryId'  => $metadata['category_id'] ?? '22', // 22 = People & Blogs
            ],
            'status' => [
                'privacyStatus'          => $metadata['privacy_status'] ?? 'public',
                'selfDeclaredMadeForKids' => (bool) ($metadata['made_for_kids'] ?? false),
            ],
        ];

        $initResponse = Http::withToken($token->access_token)
            ->withHeaders([
                'Content-Type'            => 'application/json; charset=UTF-8',
                'X-Upload-Content-Length' => (string) $fileSize,
                'X-Upload-Content-Type'   => $mimeType,
            ])
            ->post(self::VIDEOS_URL . '?uploadType=resumable&part=snippet,status', $videoMetadata);

        if ($initResponse->failed()) {
            Log::error('YouTube upload init failed', [
                'account_id' => $account->id,
                'status'     => $initResponse->status(),
                'body'       => mb_substr($initResponse->body(), 0, 500),
            ]);

            return [
                'success'       => false,
                'error_code'    => 'UPLOAD_INIT_FAILED',
                'error_message' => $initResponse->json('error.message', 'Failed to initialize YouTube upload.'),
                'response'      => $initResponse->json(),
            ];
        }

        $uploadUrl = $initResponse->header('Location');

        if (!$uploadUrl) {
            return [
                'success'       => false,
                'error_code'    => 'NO_UPLOAD_URL',
                'error_message' => 'YouTube did not return an upload URL.',
            ];
        }

        // Step 2: Upload the video binary to the resumable session URL
        $uploadResponse = Http::timeout(180)
            ->withHeaders([
                'Content-Type'   => $mimeType,
                'Content-Length' => (string) $fileSize,
            ])
            ->withBody($videoContent, $mimeType)
            ->put($uploadUrl);

        if ($uploadResponse->failed()) {
            Log::error('YouTube video upload failed', [
                'account_id' => $account->id,
                'status'     => $uploadResponse->status(),
                'body'       => mb_substr($uploadResponse->body(), 0, 500),
            ]);

            return [
                'success'       => false,
                'error_code'    => 'UPLOAD_FAILED',
                'error_message' => 'Failed to upload video to YouTube.',
                'response'      => $uploadResponse->json(),
            ];
        }

        $videoId = $uploadResponse->json('id');

        return [
            'success'          => true,
            'external_id'      => $videoId,
            'external_post_id' => $videoId,
            'url'              => "https://www.youtube.com/watch?v={$videoId}",
            'response'         => $uploadResponse->json(),
        ];
    }

    public function deletePost(SocialAccount $account, string $externalPostId): bool
    {
        $token = $this->ensureValidToken($account);

        if (!$token) {
            return false;
        }

        $response = Http::withToken($token->access_token)
            ->delete(self::VIDEOS_API_URL . '?id=' . urlencode($externalPostId));

        return $response->successful();
    }

    public function getPost(SocialAccount $account, string $externalPostId): array
    {
        $token = $this->ensureValidToken($account);

        if (!$token) {
            return ['success' => false, 'error_message' => 'Token expired.'];
        }

        return $this->authenticatedGet(
            self::VIDEOS_API_URL,
            $token->access_token,
            ['part' => 'snippet,statistics,status', 'id' => $externalPostId]
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Analytics
    // ──────────────────────────────────────────────────────────────────────

    public function getAnalytics(SocialAccount $account, array $params = []): array
    {
        $token = $this->ensureValidToken($account);

        if (!$token) {
            return ['success' => false, 'error_message' => 'Token expired.'];
        }

        $response = Http::withToken($token->access_token)->get(self::CHANNELS_URL, [
            'part' => 'statistics',
            'id'   => $account->platform_account_id,
        ]);

        if ($response->failed()) {
            return ['success' => false, 'error_message' => 'Failed to fetch YouTube analytics.'];
        }

        $channels = $response->json('items', []);

        if (empty($channels)) {
            return ['success' => false, 'error_message' => 'Channel not found.'];
        }

        return [
            'platform'   => $this->getPlatformIdentifier(),
            'statistics' => $channels[0]['statistics'] ?? [],
        ];
    }

    // ──────────────────────────────────────────────────────────────────────
    // Internal helpers
    // ──────────────────────────────────────────────────────────────────────

    protected function getScopes(): array
    {
        return [
            'https://www.googleapis.com/auth/youtube.upload',
            'https://www.googleapis.com/auth/youtube.readonly',
            'https://www.googleapis.com/auth/userinfo.profile',
        ];
    }
}
