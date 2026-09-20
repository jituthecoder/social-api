<?php

namespace App\Services\SocialProviders;

use App\Models\PostVariant;
use App\Models\SocialAccount;
use App\Models\SocialAccountToken;
use App\Services\Contracts\SocialProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

abstract class AbstractSocialProvider implements SocialProviderInterface
{
    /**
     * Build the platform-specific OAuth authorization URL.
     */
    abstract public function getAuthorizationUrl(string $state, string $redirectUri): string;

    /**
     * Exchange an OAuth authorization code for access and refresh tokens.
     */
    abstract public function exchangeCodeForTokens(string $code, string $redirectUri): array;

    /**
     * Fetch the authenticated user's profile from the platform API.
     */
    abstract public function fetchProfile(string $accessToken): array;

    /**
     * Get the OAuth scopes required by this platform.
     */
    abstract protected function getScopes(): array;

    // ──────────────────────────────────────────────────────────────────────
    // Default implementations
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Disconnect a social account by revoking its stored tokens.
     */
    public function disconnect(SocialAccount $account): bool
    {
        $account->update(['connection_status' => 'disconnected']);
        $account->token?->delete();

        return true;
    }

    /**
     * Return stored account information.
     */
    public function getAccount(SocialAccount $account): array
    {
        return $account->loadMissing('metadata')->toArray();
    }

    // ──────────────────────────────────────────────────────────────────────
    // Token helpers
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Resolve the token record for a social account.
     *
     * @throws RuntimeException if no token is found.
     */
    protected function resolveToken(SocialAccount $account): SocialAccountToken
    {
        $token = $account->token;

        if (!$token) {
            throw new RuntimeException(
                "No token found for social account [{$account->id}] on platform [{$account->platform}]."
            );
        }

        return $token;
    }

    /**
     * Determine whether the given token has expired.
     */
    protected function isTokenExpired(SocialAccountToken $token): bool
    {
        return $token->expires_at && $token->expires_at->isPast();
    }

    /**
     * Ensure the token is valid, refreshing it if necessary.
     *
     * Returns the refreshed token, or null on failure.
     */
    protected function ensureValidToken(SocialAccount $account): ?SocialAccountToken
    {
        $token = $this->resolveToken($account);

        if ($this->isTokenExpired($token)) {
            if (!$this->refreshToken($account)) {
                return null;
            }

            $token->refresh();
        }

        return $token;
    }

    // ──────────────────────────────────────────────────────────────────────
    // HTTP helpers
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Perform an authenticated GET request with standard error logging.
     */
    protected function authenticatedGet(string $url, string $accessToken, array $query = [], array $headers = []): array
    {
        $response = Http::withToken($accessToken)
            ->withHeaders($headers)
            ->get($url, $query);

        if ($response->failed()) {
            Log::error('Social API GET failed', [
                'platform' => $this->getPlatformIdentifier(),
                'url'      => $url,
                'status'   => $response->status(),
                'body'     => mb_substr($response->body(), 0, 500),
            ]);

            return [
                'success'       => false,
                'error_code'    => 'HTTP_' . $response->status(),
                'error_message' => $response->json('error.message', $response->body()),
            ];
        }

        return $response->json();
    }

    /**
     * Perform an authenticated POST request with standard error logging.
     */
    protected function authenticatedPost(string $url, string $accessToken, array $data = [], array $headers = []): array
    {
        $response = Http::withToken($accessToken)
            ->withHeaders($headers)
            ->post($url, $data);

        if ($response->failed()) {
            Log::error('Social API POST failed', [
                'platform' => $this->getPlatformIdentifier(),
                'url'      => $url,
                'status'   => $response->status(),
                'body'     => mb_substr($response->body(), 0, 500),
            ]);

            return [
                'success'       => false,
                'error_code'    => 'HTTP_' . $response->status(),
                'error_message' => $response->json('error.message', $response->body()),
                'response'      => $response->json(),
            ];
        }

        return array_merge($response->json(), ['success' => true]);
    }
}
