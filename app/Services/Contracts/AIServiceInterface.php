<?php

namespace App\Services\Contracts;

use App\Models\User;
use App\Models\Workspace;

interface AIServiceInterface
{
    public function generatePost(Workspace $workspace, User $user, string $prompt, array $options = []): array;

    public function rewritePost(Workspace $workspace, User $user, string $content, string $tone = 'professional'): array;

    public function generatePlatformVariant(Workspace $workspace, User $user, string $content, string $platform): array;

    public function generateHashtags(Workspace $workspace, User $user, string $content, int $count = 5): array;

    public function generateContentIdeas(Workspace $workspace, User $user, string $topic, int $count = 5): array;
}
