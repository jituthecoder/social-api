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
     * Default timeouts (seconds) by operation type.
     * Override in subclass if platform needs different values.
     */
    protected int $timeoutDefault   = 30;   // General API calls
    protected int $timeoutUpload    = 120;  // Image / binary upload
    protected int $timeoutVideoUp   = 300;  // Video upload
    protected int $maxRetries       = 3;    // Attempts before giving up

    /**
     * Perform an authenticated GET with retry + timeout.
     */
    protected function apiGet(string $url, string $accessToken, array $query = [], array $headers = []): \Illuminate\Http\Client\Response
    {
        return $this->withRetry(fn () =>
            Http::timeout($this->timeoutDefault)
                ->withToken($accessToken)
                ->withHeaders($headers)
                ->get($url, $query)
        );
    }

    /**
     * Perform an authenticated JSON POST with retry + timeout.
     */
    protected function apiPost(string $url, string $accessToken, array $data = [], array $headers = []): \Illuminate\Http\Client\Response
    {
        return $this->withRetry(fn () =>
            Http::timeout($this->timeoutDefault)
                ->withToken($accessToken)
                ->withHeaders($headers)
                ->post($url, $data)
        );
    }

    /**
     * Perform an authenticated form POST with retry + timeout.
     */
    protected function apiFormPost(string $url, string $accessToken, array $data = [], array $headers = []): \Illuminate\Http\Client\Response
    {
        return $this->withRetry(fn () =>
            Http::timeout($this->timeoutDefault)
                ->withToken($accessToken)
                ->withHeaders($headers)
                ->asForm()
                ->post($url, $data)
        );
    }

    /**
     * Perform an authenticated DELETE with retry + timeout.
     */
    protected function apiDelete(string $url, string $accessToken, array $headers = []): \Illuminate\Http\Client\Response
    {
        return $this->withRetry(fn () =>
            Http::timeout($this->timeoutDefault)
                ->withToken($accessToken)
                ->withHeaders($headers)
                ->delete($url)
        );
    }

    /**
     * Upload binary content (image) via PUT with retry + timeout.
     */
    protected function apiUploadBinaryPut(string $url, string $binary, string $mimeType, array $headers = []): \Illuminate\Http\Client\Response
    {
        return $this->withRetry(fn () =>
            Http::timeout($this->timeoutUpload)
                ->withHeaders(array_merge(['Content-Type' => $mimeType], $headers))
                ->withBody($binary, $mimeType)
                ->put($url)
        );
    }

    /**
     * Upload binary content (image) via multipart POST with retry + timeout.
     */
    protected function apiUploadAttach(string $url, string $accessToken, string $binary, string $filename, array $fields = []): \Illuminate\Http\Client\Response
    {
        return $this->withRetry(fn () =>
            Http::timeout($this->timeoutUpload)
                ->withToken($accessToken)
                ->attach('source', $binary, $filename)
                ->post($url, $fields)
        );
    }

    /**
     * Upload video binary via PUT with longer timeout.
     */
    protected function apiUploadVideo(string $url, string $binary, string $mimeType, int $fileSize): \Illuminate\Http\Client\Response
    {
        return $this->withRetry(fn () =>
            Http::timeout($this->timeoutVideoUp)
                ->withHeaders([
                    'Content-Type'   => $mimeType,
                    'Content-Length' => (string) $fileSize,
                ])
                ->withBody($binary, $mimeType)
                ->put($url),
            2  // Video upload: max 2 retries (expensive operation)
        );
    }

    /**
     * Retry wrapper with exponential back-off.
     *
     * Retries on:
     *  - cURL/network exceptions (timeout, SSL, connection reset)
     *  - HTTP 429 Too Many Requests (rate limit)
     *  - HTTP 5xx Server Errors (transient failures)
     *
     * @param  callable  $call      A closure that returns an Http Response.
     * @param  int|null  $maxTries  Override default maxRetries.
     */
    protected function withRetry(callable $call, ?int $maxTries = null): \Illuminate\Http\Client\Response
    {
        $tries   = $maxTries ?? $this->maxRetries;
        $attempt = 0;

        while (true) {
            $attempt++;
            try {
                /** @var \Illuminate\Http\Client\Response $response */
                $response = $call();

                // Retry on rate-limit or server errors (5xx)
                $status = $response->status();
                if ($attempt < $tries && ($status === 429 || $status >= 500)) {
                    $wait = $status === 429
                        ? (int) ($response->header('Retry-After') ?: (2 ** $attempt))
                        : (2 ** $attempt);

                    Log::warning("[{$this->getPlatformIdentifier()}] HTTP {$status} on attempt {$attempt}. Retrying in {$wait}s.", [
                        'url'    => $response->effectiveUri()?->__toString(),
                        'status' => $status,
                    ]);
                    sleep($wait);
                    continue;
                }

                return $response;

            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                // Network-level error (cURL timeout, SSL handshake, connection reset)
                Log::warning("[{$this->getPlatformIdentifier()}] Connection error on attempt {$attempt}: " . $e->getMessage());

                if ($attempt >= $tries) {
                    Log::error("[{$this->getPlatformIdentifier()}] All {$tries} attempts failed. Last error: " . $e->getMessage());
                    // Re-throw so the caller can handle it gracefully
                    throw $e;
                }

                $wait = 2 ** $attempt; // 2s, 4s, 8s…
                Log::info("[{$this->getPlatformIdentifier()}] Retrying in {$wait}s (attempt {$attempt}/{$tries}).");
                sleep($wait);
            }
        }
    }

    /**
     * @deprecated Use apiGet() instead.
     */
    protected function authenticatedGet(string $url, string $accessToken, array $query = [], array $headers = []): array
    {
        $response = $this->apiGet($url, $accessToken, $query, $headers);

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
     * @deprecated Use apiPost() instead.
     */
    protected function authenticatedPost(string $url, string $accessToken, array $data = [], array $headers = []): array
    {
        $response = $this->apiPost($url, $accessToken, $data, $headers);

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

