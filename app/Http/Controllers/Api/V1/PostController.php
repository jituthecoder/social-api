<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\ApiResponseTrait;
use App\Http\Requests\Post\CreatePostRequest;
use App\Models\Post;
use App\Models\Workspace;
use App\Services\PostService;
use App\Services\PublishingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PostController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        protected PostService $postService,
        protected PublishingService $publishingService
    ) {}

    protected function resolveWorkspace(Request $request): Workspace
    {
        $workspaceId = $request->header('X-Workspace-Id') ?? $request->query('workspace_id');
        
        if ($workspaceId) {
            $workspace = Workspace::findOrFail($workspaceId);
            Gate::authorize('view', $workspace);
            return $workspace;
        }

        $workspace = $request->user()->workspaces()->first();
        if (!$workspace) {
            abort(403, 'No active workspace found for user.');
        }

        return $workspace;
    }

    public function index(Request $request): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);

        $query = Post::where('workspace_id', $workspace->id)
            ->with(['variants', 'targets.socialAccount', 'media', 'user:id,name,email']);

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('platform')) {
            $query->whereHas('variants', function ($q) use ($request) {
                $q->where('platform', $request->query('platform'));
            });
        }

        if ($request->filled('date')) {
            $query->whereDate('created_at', $request->query('date'));
        }

        if ($request->filled('scheduled_date')) {
            $query->whereDate('scheduled_at', $request->query('scheduled_date'));
        }

        $posts = $query->orderBy('created_at', 'desc')->paginate($request->query('per_page', 15));

        return $this->successResponse($posts, 'Posts retrieved successfully');
    }

    public function store(CreatePostRequest $request): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);
        Gate::authorize('create', [Post::class, $workspace]);

        $post = $this->postService->createPost(
            $workspace,
            $request->user(),
            $request->validated()
        );

        if ($request->boolean('publish_now')) {
            $this->publishingService->publishPost($post);
            $post->load(['variants', 'targets.socialAccount', 'media', 'publishAttempts']);
        }

        return $this->successResponse($post, 'Post created successfully', 201);
    }

    public function publish(Request $request, Post $post): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);
        Gate::authorize('update', [$post, $workspace]);

        $published = $this->publishingService->publishPost($post);

        $post->load(['variants', 'targets.socialAccount', 'media', 'publishAttempts']);

        return $this->successResponse([
            'post' => $post,
            'success' => $published,
        ], $published ? 'Post published successfully' : 'Publishing failed or partially failed');
    }

    public function show(Request $request, Post $post): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);
        Gate::authorize('view', [$post, $workspace]);

        return $this->successResponse(
            $post->load(['variants', 'targets.socialAccount', 'media', 'publishAttempts']),
            'Post details retrieved'
        );
    }

    public function update(Request $request, Post $post): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);
        Gate::authorize('update', [$post, $workspace]);

        $updatedPost = $this->postService->updatePost(
            $post,
            $workspace,
            $request->user(),
            $request->all()
        );

        return $this->successResponse($updatedPost, 'Post updated successfully');
    }

    public function destroyTarget(Request $request, Post $post, \App\Models\PostTarget $target): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);
        Gate::authorize('delete', [$post, $workspace]);

        if ($target->post_id !== $post->id) {
            return $this->errorResponse('Target does not belong to this post', 400);
        }

        $result = $this->postService->deletePostTarget($post, $target, $workspace, $request->user());

        return $this->successResponse($result, 'Post removed from platform successfully');
    }

    public function destroy(Request $request, Post $post): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);
        Gate::authorize('delete', [$post, $workspace]);

        $this->postService->deletePost($post, $workspace, $request->user());

        return $this->successResponse(null, 'Post deleted successfully');
    }
}
