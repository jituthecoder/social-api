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
        protected AuditLogService $auditLogService
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
                'content' => $data['content'],
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
            if (!empty($data['variants']) && is_array($data['variants'])) {
                foreach ($data['variants'] as $variantData) {
                    $post->variants()->create([
                        'social_account_id' => $variantData['social_account_id'] ?? null,
                        'platform' => $variantData['platform'],
                        'content' => $variantData['content'] ?? $data['content'],
                        'hashtags' => $variantData['hashtags'] ?? [],
                        'metadata' => $variantData['metadata'] ?? [],
                        'status' => 'pending',
                        'scheduled_at' => $scheduledAtUtc,
                    ]);
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

    public function deletePost(Post $post, Workspace $workspace, User $user): bool
    {
        $this->auditLogService->log('post.deleted', $workspace, $user, ['post_id' => $post->id]);
        return $post->delete();
    }
}
