<?php

namespace App\Jobs;

use App\Models\Post;
use App\Services\PublishingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class PublishPostJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300, 900]; // Retry backoff: 1 min, 5 min, 15 min

    public function __construct(
        public Post $post
    ) {
        $this->onQueue('social-publishing');
    }

    public function handle(PublishingService $publishingService): void
    {
        $publishingService->publishPost($this->post);
    }

    public function failed(?Throwable $exception): void
    {
        $this->post->update(['status' => 'failed']);
    }
}
