<?php

namespace App\Services;

use App\Models\Post;
use App\Models\PostVariant;
use App\Models\PublishAttempt;
use App\Models\SocialAccount;
use App\Services\Contracts\SocialProviderInterface;
use Exception;
use Illuminate\Support\Facades\Log;

class PublishingService
{
    protected array $providers = [];

    public function __construct(
        protected AuditLogService $auditLogService,
        protected UsageService $usageService
    ) {}

    public function registerProvider(SocialProviderInterface $provider): void
    {
        $this->providers[$provider->getPlatformIdentifier()] = $provider;
    }

    public function getProvider(string $platform): ?SocialProviderInterface
    {
        return $this->providers[$platform] ?? null;
    }

    public function publishPost(Post $post): bool
    {
        $post->update(['status' => 'publishing']);
        $hasErrors = false;
        $publishedCount = 0;

        $post->load(['targets.socialAccount', 'variants']);

        foreach ($post->targets as $target) {
            $socialAccount = $target->socialAccount;
            if (!$socialAccount || $socialAccount->connection_status !== 'connected') {
                $this->recordAttempt($post, null, $socialAccount, 'token_expired', 'TOKEN_EXPIRED', 'Social account connection expired or disconnected.');
                $target->update(['status' => 'failed']);
                $hasErrors = true;
                continue;
            }

            $variant = $post->variants
                ->where('social_account_id', $socialAccount->id)
                ->first() 
                ?? $post->variants->where('platform', $socialAccount->platform)->first();

            try {
                $provider = $this->getProvider($socialAccount->platform);

                if (!$provider) {
                    // Framework placeholder when provider module is registered in later provider-specific phase
                    $result = [
                        'success' => true,
                        'external_id' => 'mock_ext_' . uniqid(),
                        'response' => ['note' => 'Provider architecture ready. Platform integration placeholder.'],
                    ];
                } else {
                    $result = $provider->publishPost($socialAccount, $variant);
                }

                if ($result['success'] ?? false) {
                    $target->update(['status' => 'published']);
                    if ($variant) {
                        $variant->update(['status' => 'published', 'published_at' => now()]);
                    }

                    $this->recordAttempt($post, $variant, $socialAccount, 'success', null, null, $result['response'] ?? []);
                    $publishedCount++;
                } else {
                    $target->update(['status' => 'failed']);
                    if ($variant) {
                        $variant->update(['status' => 'failed']);
                    }

                    $this->recordAttempt($post, $variant, $socialAccount, 'failed', $result['error_code'] ?? 'PUB_ERR', $result['error_message'] ?? 'Publishing failed.', $result['response'] ?? []);
                    $hasErrors = true;
                }
            } catch (Exception $e) {
                Log::error("Publishing error for post {$post->id} on {$socialAccount->platform}: " . $e->getMessage());

                $target->update(['status' => 'failed']);
                if ($variant) {
                    $variant->update(['status' => 'failed']);
                }

                $this->recordAttempt($post, $variant, $socialAccount, 'failed', 'EXCEPTION', 'An unexpected error occurred during publishing.', ['exception' => $e->getMessage()]);
                $hasErrors = true;
            }
        }

        $finalStatus = match (true) {
            $publishedCount > 0 && !$hasErrors => 'published',
            $publishedCount > 0 && $hasErrors => 'partially_failed',
            default => 'failed',
        };

        $post->update([
            'status' => $finalStatus,
            'published_at' => $publishedCount > 0 ? now() : null,
        ]);

        if ($publishedCount > 0) {
            $this->usageService->recordUsage($post->workspace, 'posts_published', $publishedCount);
        }

        $this->auditLogService->log('post.published', $post->workspace, null, [
            'post_id' => $post->id,
            'status' => $finalStatus,
            'published_count' => $publishedCount,
        ]);

        return $finalStatus === 'published';
    }

    protected function recordAttempt(
        Post $post,
        ?PostVariant $variant,
        ?SocialAccount $socialAccount,
        string $status,
        ?string $errorCode = null,
        ?string $errorMessage = null,
        array $rawResponse = []
    ): PublishAttempt {
        return PublishAttempt::create([
            'post_id' => $post->id,
            'post_variant_id' => $variant?->id,
            'social_account_id' => $socialAccount?->id,
            'status' => $status,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'raw_response' => $rawResponse,
            'attempted_at' => now(),
        ]);
    }
}
