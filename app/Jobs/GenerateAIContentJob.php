<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Contracts\AIServiceInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateAIContentJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Workspace $workspace,
        public User $user,
        public string $prompt
    ) {
        $this->onQueue('ai');
    }

    public function handle(AIServiceInterface $aiService): void
    {
        $aiService->generatePost($this->workspace, $this->user, $this->prompt);
    }
}
