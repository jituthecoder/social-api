<?php

namespace App\Services\SocialProviders;

use App\Models\Media;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Enterprise X (Twitter) API v2 Provider.
 *
 * Implements:
 * - OAuth 2.0 with PKCE (RFC 7636) & offline.access (refresh tokens).
 * - X API v2 Tweets endpoint (POST /2/tweets).
 * - Media attachment via v1.1 upload endpoint with OAuth 2.0 user context.
 * - Profile discovery, live URL generation (https://x.com/{username}/status/{id}).
 * - Post analytics, deletion, and token refreshing.
 */
class TwitterProvider extends AbstractSocialProvider
{
    // ──────────────────────────────────────────────────────────────────────
    // Endpoints
    // ──────────────────────────────────────────────────────────────────────

    protected const AUTH_URL     = 'https://twitter.com/i/oauth2/authorize';
    protected const TOKEN_URL    = 'https://api.twitter.com/2/oauth2/token';
    protected const USER_ME_URL  = 'https://api.twitter.com/2/users/me';
    protected const TWEETS_URL   = 'https://api.twitter.com/2/tweets';
    protected const UPLOAD_URL   = 'https://upload.twitter.com/1.1/media/upload.json';

    public function getPlatformIdentifier(): string
    {
        return 'twitter';
    }

    // ──────────────────────────────────────────────────────────────────────
    // OAuth 2.0 with PKCE Flow
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Build the X / Twitter OAuth 2.0 authorization URL with PKCE (S256).
     */
    public function getAuthorizationUrl(string $state, string $redirectUri): string
    {
        $clientId = $this->getClientId();

        // Generate PKCE code verifier (64 random chars) and code challenge (S256)
        $codeVerifier = Str::random(64);
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        // Store code verifier in Cache for 30 minutes, keyed by state token
        Cache::put("twitter_pkce_{$state}", $codeVerifier, now()->addMinutes(30));

        $params = [
            'response_type'         => 'code',
            'client_id'             => $clientId,
            'redirect_uri'          => $redirectUri,
            'scope'                 => implode(' ', $this->getScopes()),
            'state'                 => $state,
            'code_challenge'        => $codeChallenge,
            'code_challenge_method' => 'S256',
        ];

        return self::AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Exchange OAuth 2.0 authorization code for access and refresh tokens.
     */
    public function exchangeCodeForTokens(string $code, string $redirectUri, ?string $state = null): array
    {
        $clientId     = $this->getClientId();
        $clientSecret = $this->getClientSecret();

        // Retrieve PKCE code verifier from cache
        $codeVerifier = $state ? Cache::pull("twitter_pkce_{$state}") : null;

        if (!$codeVerifier) {
            // Fallback: Check if verifier was passed directly or stored under recent session
            $codeVerifier = Cache::get('twitter_pkce_latest') ?? Str::random(64);
        }

        $params = [
            'code'          => $code,
            'grant_type'    => 'authorization_code',
            'client_id'     => $clientId,
            'redirect_uri'  => $redirectUri,
            'code_verifier' => $codeVerifier,
        ];

        $request = Http::asForm();

        // Confidential client with secret: send HTTP Basic Auth
        if (!empty($clientSecret)) {
            $request = $request->withBasicAuth($clientId, $clientSecret);
        }

        $response = $request->post(self::TOKEN_URL, $params);

        if ($response->failed()) {
            Log::error('X (Twitter) token exchange failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            throw new RuntimeException(
                'Failed to exchange Twitter authorization code: ' .
                $response->json('error_description', $response->json('error', 'Unknown error'))
            );
        }

        $data = $response->json();

        return [
            'access_token'  => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? null,
            'expires_in'    => $data['expires_in'] ?? 7200,
            'scopes'        => isset($data['scope']) ? explode(' ', $data['scope']) : $this->getScopes(),
        ];
    }

    /**
     * Fetch the authenticated X user's profile.
     */
    public function fetchProfile(string $accessToken): array
    {
        $response = Http::withToken($accessToken)->get(self::USER_ME_URL, [
            'user.fields' => 'id,name,username,profile_image_url,description,verified',
        ]);

        if ($response->failed()) {
            Log::error('X (Twitter) profile fetch failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            throw new RuntimeException(
                'Failed to fetch Twitter user profile: ' .
                $response->json('error.message', $response->json('title', 'Unknown error'))
            );
        }

        $data = $response->json('data', []);

        return [
            'platform_account_id' => $data['id'] ?? '',
            'name'                => $data['name'] ?? 'X User',
            'username'            => $data['username'] ?? null,
            'avatar_url'          => $data['profile_image_url'] ?? null,
            'account_type'        => 'profile',
        ];
    }

    /**
     * Connect an X (Twitter) account to a workspace.
     */
    public function connect(array $credentials): SocialAccount
    {
        $state       = $credentials['state'] ?? null;
        $tokens      = $this->exchangeCodeForTokens($credentials['code'], $credentials['redirect_uri'], $state);
        $profile     = $this->fetchProfile($tokens['access_token']);
        $workspaceId = $credentials['workspace_id'];

        $workspace = Workspace::findOrFail($workspaceId);

        $account = $workspace->socialAccounts()->updateOrCreate(
            [
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
                'refresh_token' => $tokens['refresh_token'] ?? null,
                'expires_at'    => now()->addSeconds($tokens['expires_in']),
                'scopes'        => $tokens['scopes'],
            ]
        );

        return $account;
    }

    /**
     * Refresh the X (Twitter) OAuth 2.0 access token using offline.access refresh_token.
     */
    public function refreshToken(SocialAccount $account): bool
    {
        $token = $this->resolveToken($account);

        if (!$token || empty($token->refresh_token)) {
            Log::warning("Cannot refresh X token for account {$account->id}: No refresh token found.");
            return false;
        }

        $clientId     = $this->getClientId();
        $clientSecret = $this->getClientSecret();

        $params = [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $token->refresh_token,
            'client_id'     => $clientId,
        ];

        $request = Http::asForm();
        if (!empty($clientSecret)) {
            $request = $request->withBasicAuth($clientId, $clientSecret);
        }

        $response = $request->post(self::TOKEN_URL, $params);

        if ($response->failed()) {
            Log::warning("X (Twitter) token refresh failed for account {$account->id}", [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            $account->update(['connection_status' => 'expired']);
            return false;
        }

        $data = $response->json();

        $token->update([
            'access_token'  => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $token->refresh_token,
            'expires_at'    => now()->addSeconds($data['expires_in'] ?? 7200),
        ]);

        $account->update(['connection_status' => 'connected']);

        return true;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Publishing Engine
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Validate tweet variant before publishing.
     */
    public function validatePost(PostVariant $variant): array
    {
        $errors  = [];
        $content = $variant->content ?? '';
        $post    = $variant->post;

        $hasMedia = $post && $post->media()->exists();

        if (empty(trim($content)) && !$hasMedia) {
            $errors[] = 'Tweet must contain text or attached media.';
        }

        // Twitter/X character limit is 280 characters for standard tier
        if (mb_strlen($content) > 280) {
            $errors[] = 'Tweet exceeds maximum length of 280 characters (current: ' . mb_strlen($content) . ').';
        }

        return $errors;
    }

    /**
     * Publish a Tweet via X API v2 (POST /2/tweets).
     */
    public function publishPost(SocialAccount $account, PostVariant $variant): array
    {
        $token = $this->ensureValidToken($account);

        if (!$token) {
            return [
                'success'       => false,
                'error_code'    => 'TOKEN_EXPIRED',
                'error_message' => 'X (Twitter) access token expired and could not be refreshed.',
            ];
        }

        $accessToken = $token->access_token;
        $content     = $variant->content ?? '';
        $post        = $variant->post;

        // Build payload
        $payload = [
            'text' => $content,
        ];

        // Handle attached media if present
        $mediaItems = $post?->media ?? collect();
        if ($mediaItems->isNotEmpty()) {
            $mediaIds = [];
            foreach ($mediaItems->take(4) as $mediaItem) {
                $mediaId = $this->uploadMediaItem($accessToken, $mediaItem);
                if ($mediaId) {
                    $mediaIds[] = $mediaId;
                }
            }

            if (!empty($mediaIds)) {
                $payload['media'] = [
                    'media_ids' => $mediaIds,
                ];
            }
        }

        // Execute Tweet creation via v2 API
        $response = Http::withToken($accessToken)
            ->contentType('application/json')
            ->post(self::TWEETS_URL, $payload);

        if ($response->failed()) {
            Log::error('X (Twitter) publish failed', [
                'account_id' => $account->id,
                'status'     => $response->status(),
                'body'       => $response->body(),
            ]);

            $errorTitle  = $response->json('title', 'Twitter API Error');
            $errorDetail = $response->json('detail', $errorTitle);

            if ($response->status() === 402 || str_contains(strtolower($errorDetail), 'credits depleted')) {
                $errorDetail = 'X API Error: 402 Payment Required (Credits Depleted). Your X Developer Console app is configured under "Pay Per Use" with 0 credits balance. Please check console.x.com -> Billing -> Credits.';
            }

            return [
                'success'       => false,
                'error_code'    => (string) ($response->json('status') ?? $response->status()),
                'error_message' => $errorDetail,
                'response'      => $response->json() ?: ['body' => $response->body()],
            ];
        }

        $tweetData = $response->json('data', []);
        $tweetId   = $tweetData['id'] ?? null;
        $username  = $account->username ?: 'i';

        $liveUrl = $tweetId ? "https://x.com/{$username}/status/{$tweetId}" : null;

        return [
            'success'      => true,
            'external_id'  => $tweetId,
            'external_url' => $liveUrl,
            'response'     => $response->json(),
        ];
    }

    /**
     * Upload an image to Twitter v1.1 upload endpoint.
     */
    protected function uploadMediaItem(string $accessToken, Media $mediaItem): ?string
    {
        try {
            $binary = $mediaItem->getBinaryContent();

            if (!$binary) {
                $url = $mediaItem->url;
                if (!empty($url)) {
                    $binary = @file_get_contents($url);
                }
            }

            if (!$binary) {
                return null;
            }

            $res = Http::withToken($accessToken)
                ->attach('media', $binary, $mediaItem->original_name ?: 'upload.jpg')
                ->post(self::UPLOAD_URL);

            if ($res->successful()) {
                return (string) ($res->json('media_id_string') ?? $res->json('media_id'));
            }

            Log::warning('Twitter media upload failed: ' . $res->body());
        } catch (\Throwable $e) {
            Log::warning('Twitter media upload exception: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Upload media interface method.
     */
    public function uploadMedia(SocialAccount $account, string $mediaUrl, string $mimeType): array
    {
        $token = $this->ensureValidToken($account);
        if (!$token) {
            return ['success' => false, 'error_message' => 'Token expired.'];
        }

        try {
            $binary = @file_get_contents($mediaUrl);
            if (!$binary) {
                return ['success' => false, 'error_message' => 'Unable to read media.'];
            }

            $res = Http::withToken($token->access_token)
                ->attach('media', $binary, 'media.jpg')
                ->post(self::UPLOAD_URL);

            if ($res->successful()) {
                return [
                    'success'  => true,
                    'media_id' => (string) $res->json('media_id_string'),
                ];
            }

            return ['success' => false, 'error_message' => $res->body()];
        } catch (\Throwable $e) {
            return ['success' => false, 'error_message' => $e->getMessage()];
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // Post Management & Analytics
    // ──────────────────────────────────────────────────────────────────────

    public function deletePost(SocialAccount $account, string $externalPostId): bool
    {
        $token = $this->ensureValidToken($account);
        if (!$token) {
            return false;
        }

        $res = Http::withToken($token->access_token)
            ->delete(self::TWEETS_URL . "/{$externalPostId}");

        return $res->successful() && ($res->json('data.deleted') === true);
    }

    public function getPost(SocialAccount $account, string $externalPostId): array
    {
        $token = $this->ensureValidToken($account);
        if (!$token) {
            return ['success' => false, 'error_message' => 'Token expired.'];
        }

        $res = Http::withToken($token->access_token)
            ->get(self::TWEETS_URL . "/{$externalPostId}", [
                'tweet.fields' => 'id,text,created_at,public_metrics',
            ]);

        return $res->successful() ? $res->json('data', []) : ['error' => $res->body()];
    }

    public function getAnalytics(SocialAccount $account, array $params = []): array
    {
        $token = $this->ensureValidToken($account);
        if (!$token) {
            return ['success' => false, 'error_message' => 'Token expired.'];
        }

        $userId = $account->platform_account_id;

        // Fetch user's latest tweets with public metrics
        $res = Http::withToken($token->access_token)
            ->get("https://api.twitter.com/2/users/{$userId}/tweets", [
                'max_results'  => 10,
                'tweet.fields' => 'public_metrics,created_at',
            ]);

        if ($res->successful()) {
            $tweets = $res->json('data', []);
            $impressions = 0;
            $likes       = 0;
            $retweets    = 0;
            $replies     = 0;

            foreach ($tweets as $tweet) {
                $metrics = $tweet['public_metrics'] ?? [];
                $impressions += $metrics['impression_count'] ?? 0;
                $likes       += $metrics['like_count'] ?? 0;
                $retweets    += $metrics['retweet_count'] ?? 0;
                $replies     += $metrics['reply_count'] ?? 0;
            }

            return [
                'total_tweets'      => count($tweets),
                'total_impressions' => $impressions,
                'total_likes'       => $likes,
                'total_retweets'    => $retweets,
                'total_replies'     => $replies,
            ];
        }

        return ['note' => 'X metrics unavailable'];
    }

    // ──────────────────────────────────────────────────────────────────────
    // Internal Helpers & Scopes
    // ──────────────────────────────────────────────────────────────────────

    protected function getScopes(): array
    {
        return [
            'tweet.read',
            'tweet.write',
            'users.read',
            'offline.access',
        ];
    }

    protected function getClientId(): string
    {
        return (string) config('services.twitter.client_id', config('services.x.client_id', env('TWITTER_CLIENT_ID', '')));
    }

    protected function getClientSecret(): string
    {
        return (string) config('services.twitter.client_secret', config('services.x.client_secret', env('TWITTER_CLIENT_SECRET', '')));
    }
}
