<?php

namespace App\Services;

use App\Models\Post;
use App\Models\PostVariant;
use App\Models\ScheduledPost;
use App\Models\User;
use App\Models\Workspace;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PostService
{
    public function __construct(
        protected SchedulingService $schedulingService,
        protected AuditLogService $auditLogService,
        protected PublishingService $publishingService
    ) {}

    public function createPost(Workspace $workspace, User $user, array $data): Post
    {
        return DB::transaction(function () use ($workspace, $user, $data) {
            $scheduledAtUtc = null;
            if (!empty($data['scheduled_at'])) {
                $scheduledAtUtc = $this->schedulingService->toUtc(
                    $data['scheduled_at'],
                    $workspace->timezone
                );
            }

            $status = !empty($data['is_scheduled']) && $scheduledAtUtc ? 'scheduled' : 'draft';

            $post = Post::create([
                'workspace_id' => $workspace->id,
                'user_id' => $user->id,
                'title' => $data['title'] ?? null,
                'content' => $data['content'] ?? '',
                'status' => $status,
                'scheduled_at' => $scheduledAtUtc,
                'settings' => $data['settings'] ?? [],
            ]);

            // Attach target social accounts if provided
            if (!empty($data['social_account_ids'])) {
                foreach ($data['social_account_ids'] as $accountId) {
                    $post->targets()->create([
                        'social_account_id' => $accountId,
                        'status' => 'pending',
                    ]);
                }
            }

            // Create platform variants if provided, or default variants
            $createdPlatforms = [];
            if (!empty($data['variants']) && is_array($data['variants'])) {
                foreach ($data['variants'] as $variantData) {
                    $post->variants()->create([
                        'social_account_id' => $variantData['social_account_id'] ?? null,
                        'platform' => $variantData['platform'],
                        'content' => $variantData['content'] ?? ($data['content'] ?? ''),
                        'hashtags' => $variantData['hashtags'] ?? [],
                        'metadata' => $variantData['metadata'] ?? [],
                        'status' => 'pending',
                        'scheduled_at' => $scheduledAtUtc,
                    ]);
                    $createdPlatforms[] = $variantData['platform'];
                }
            }

            // Ensure every targeted account has a variant created
            if (!empty($data['social_account_ids'])) {
                $targetAccounts = \App\Models\SocialAccount::whereIn('id', $data['social_account_ids'])->get();
                foreach ($targetAccounts as $account) {
                    if (!in_array($account->platform, $createdPlatforms)) {
                        $post->variants()->create([
                            'social_account_id' => $account->id,
                            'platform' => $account->platform,
                            'content' => $data['content'] ?? '',
                            'hashtags' => [],
                            'metadata' => [],
                            'status' => 'pending',
                            'scheduled_at' => $scheduledAtUtc,
                        ]);
                        $createdPlatforms[] = $account->platform;
                    }
                }
            }

            // Attach media items if provided
            if (!empty($data['media_ids']) && is_array($data['media_ids'])) {
                foreach ($data['media_ids'] as $index => $mediaId) {
                    $post->media()->attach($mediaId, ['position' => $index]);
                }
            }

            // If scheduled, add entry to scheduled_posts execution index
            if ($status === 'scheduled' && $scheduledAtUtc) {
                ScheduledPost::create([
                    'post_id' => $post->id,
                    'workspace_id' => $workspace->id,
                    'scheduled_at' => $scheduledAtUtc,
                    'status' => 'pending',
                ]);
            }

            $this->auditLogService->log('post.created', $workspace, $user, ['post_id' => $post->id]);

            return $post->load(['variants', 'targets.socialAccount', 'media']);
        });
    }

    public function updatePost(Post $post, Workspace $workspace, User $user, array $data): Post
    {
        return DB::transaction(function () use ($post, $workspace, $user, $data) {
            $scheduledAtUtc = $post->scheduled_at;

            if (array_key_exists('scheduled_at', $data)) {
                $scheduledAtUtc = $data['scheduled_at']
                    ? $this->schedulingService->toUtc($data['scheduled_at'], $workspace->timezone)
                    : null;
            }

            $status = $data['status'] ?? $post->status;
            if ($scheduledAtUtc && $status === 'draft') {
                $status = 'scheduled';
            }

            $post->update([
                'title' => $data['title'] ?? $post->title,
                'content' => $data['content'] ?? $post->content,
                'status' => $status,
                'scheduled_at' => $scheduledAtUtc,
                'settings' => $data['settings'] ?? $post->settings,
            ]);

            if (isset($data['media_ids'])) {
                $post->media()->detach();
                foreach ($data['media_ids'] as $index => $mediaId) {
                    $post->media()->attach($mediaId, ['position' => $index]);
                }
            }

            if (isset($data['social_account_ids']) && is_array($data['social_account_ids'])) {
                if (in_array($post->status, ['draft', 'scheduled'])) {
                    $post->targets()->whereNotIn('social_account_id', $data['social_account_ids'])->delete();
                    $post->variants()->whereNotIn('social_account_id', $data['social_account_ids'])->delete();
                }
                $existingAccountIds = $post->targets()->pluck('social_account_id')->toArray();
                foreach ($data['social_account_ids'] as $accountId) {
                    if (!in_array($accountId, $existingAccountIds)) {
                        $post->targets()->create([
                            'social_account_id' => $accountId,
                            'status' => 'pending',
                        ]);
                    }
                }
            }

            if (!empty($data['variants']) && is_array($data['variants'])) {
                foreach ($data['variants'] as $vData) {
                    $post->variants()->updateOrCreate(
                        [
                            'post_id' => $post->id,
                            'platform' => $vData['platform'],
                        ],
                        [
                            'content' => $vData['content'] ?? $post->content,
                            'metadata' => $vData['metadata'] ?? [],
                            'status' => 'pending',
                        ]
                    );
                }
            }

            if ($status === 'scheduled' && $scheduledAtUtc) {
                ScheduledPost::updateOrCreate(
                    ['post_id' => $post->id],
                    [
                        'workspace_id' => $workspace->id,
                        'scheduled_at' => $scheduledAtUtc,
                        'status' => 'pending',
                    ]
                );
            } elseif ($status !== 'scheduled') {
                ScheduledPost::where('post_id', $post->id)->delete();
            }

            $this->auditLogService->log('post.updated', $workspace, $user, ['post_id' => $post->id]);

            return $post->load(['variants', 'targets.socialAccount', 'media']);
        });
    }

    public function deletePostTarget(Post $post, PostTarget $target, Workspace $workspace, User $user): array
    {
        $target->load('socialAccount');
        $socialAccount = $target->socialAccount;
        $platformDeleted = false;
        $platformName = $socialAccount?->platform ?? 'unknown';

        // 1. Delete on the remote social media platform if external ID exists
        if (
            $socialAccount &&
            !empty($target->external_post_id) &&
            $socialAccount->connection_status === 'connected'
        ) {
            try {
                $provider = $this->publishingService->getProvider($socialAccount->platform);
                if ($provider) {
                    $platformDeleted = $provider->deletePost($socialAccount, $target->external_post_id);
                    \Illuminate\Support\Facades\Log::info("Individual platform delete on {$socialAccount->platform}", [
                        'post_id' => $post->id,
                        'target_id' => $target->id,
                        'external_post_id' => $target->external_post_id,
                        'deleted' => $platformDeleted,
                    ]);
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning("Could not delete post from {$socialAccount->platform}: " . $e->getMessage(), [
                    'post_id' => $post->id,
                    'target_id' => $target->id,
                ]);
            }
        }

        // 2. Delete corresponding PostVariant for this platform / account
        if ($socialAccount) {
            $post->variants()
                ->where(function ($q) use ($socialAccount) {
                    $q->where('social_account_id', $socialAccount->id)
                      ->orWhere('platform', $socialAccount->platform);
                })
                ->delete();
        }

        // 3. Delete this target record
        $target->delete();

        // 4. Update post status based on remaining targets
        $remainingTargets = $post->targets()->get();
        if ($remainingTargets->isEmpty()) {
            $post->update(['status' => 'draft']);
        } else {
            $hasPublished = $remainingTargets->contains(fn($t) => $t->status === 'published');
            $hasFailed = $remainingTargets->contains(fn($t) => $t->status === 'failed');

            if ($hasPublished && !$hasFailed) {
                $post->update(['status' => 'published']);
            } elseif ($hasPublished && $hasFailed) {
                $post->update(['status' => 'partially_failed']);
            } elseif ($hasFailed) {
                $post->update(['status' => 'failed']);
            }
        }

        $this->auditLogService->log('post.target_deleted', $workspace, $user, [
            'post_id' => $post->id,
            'platform' => $platformName,
            'platform_deleted' => $platformDeleted,
        ]);

        return [
            'platform' => $platformName,
            'remote_deleted' => $platformDeleted,
            'remaining_targets_count' => $remainingTargets->count(),
        ];
    }

    public function deletePost(Post $post, Workspace $workspace, User $user): bool
    {
        // ── Step 1: Delete from all social platforms that have an external post ID ──
        $post->load(['targets.socialAccount']);

        foreach ($post->targets as $target) {
            $socialAccount = $target->socialAccount;

            // Only attempt if we have the external post ID and a connected account
            if (
                empty($target->external_post_id) ||
                !$socialAccount ||
                $socialAccount->connection_status !== 'connected'
            ) {
                continue;
            }

            try {
                $provider = $this->publishingService->getProvider($socialAccount->platform);

                if ($provider) {
                    $deleted = $provider->deletePost($socialAccount, $target->external_post_id);

                    \Illuminate\Support\Facades\Log::info("Platform delete on {$socialAccount->platform}", [
                        'post_id'          => $post->id,
                        'external_post_id' => $target->external_post_id,
                        'deleted'          => $deleted,
                    ]);
                }
            } catch (\Exception $e) {
                // Log error but don't block local deletion
                \Illuminate\Support\Facades\Log::warning("Could not delete post from {$socialAccount->platform}: " . $e->getMessage(), [
                    'post_id'          => $post->id,
                    'external_post_id' => $target->external_post_id,
                ]);
            }
        }

        // ── Step 2: Delete from local database ───────────────────────────────
        $this->auditLogService->log('post.deleted', $workspace, $user, ['post_id' => $post->id]);
        return $post->delete();
    }
}
