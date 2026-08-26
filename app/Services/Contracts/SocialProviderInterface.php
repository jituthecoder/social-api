<?php

namespace App\Services\Contracts;

use App\Models\PostVariant;
use App\Models\SocialAccount;

interface SocialProviderInterface
{
    public function getPlatformIdentifier(): string;

    public function connect(array $credentials): SocialAccount;

    public function disconnect(SocialAccount $account): bool;

    public function getAccount(SocialAccount $account): array;

    public function refreshToken(SocialAccount $account): bool;

    public function validatePost(PostVariant $variant): array;

    public function uploadMedia(SocialAccount $account, string $mediaUrl, string $mimeType): array;

    public function publishPost(SocialAccount $account, PostVariant $variant): array;

    public function deletePost(SocialAccount $account, string $externalPostId): bool;

    public function getPost(SocialAccount $account, string $externalPostId): array;

    public function getAnalytics(SocialAccount $account, array $params = []): array;
}
