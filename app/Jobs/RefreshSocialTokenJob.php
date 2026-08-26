<?php

namespace App\Jobs;

use App\Models\SocialAccount;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RefreshSocialTokenJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public SocialAccount $socialAccount
    ) {
        $this->onQueue('social-publishing');
    }

    public function handle(): void
    {
        // Token refresh execution placeholder for social provider phase
    }
}
