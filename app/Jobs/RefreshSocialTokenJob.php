<?php

namespace App\Jobs;

use App\Models\SocialAccount;
use App\Services\SocialProviders\AbstractSocialProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class RefreshSocialTokenJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public array $backoff = [300, 900]; // 5 min, 15 min

    public function __construct(
        public SocialAccount $socialAccount
    ) {
        $this->onQueue('social-publishing');
    }

    public function handle(): void
    {
        $platform = $this->socialAccount->platform;
        $class    = config('services.social_providers.' . $platform);

        if (!$class || !class_exists($class)) {
            Log::info("No provider registered for platform [{$platform}], skipping token refresh.", [
                'account_id' => $this->socialAccount->id,
            ]);
            return;
        }

        /** @var AbstractSocialProvider $provider */
        $provider = app($class);
        $refreshed = $provider->refreshToken($this->socialAccount);

        Log::info("Token refresh " . ($refreshed ? 'succeeded' : 'failed') . " for {$platform}", [
            'account_id' => $this->socialAccount->id,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error("RefreshSocialTokenJob failed for account [{$this->socialAccount->id}]", [
            'platform' => $this->socialAccount->platform,
            'error'    => $exception?->getMessage(),
        ]);

        $this->socialAccount->update(['connection_status' => 'expired']);
    }
}

