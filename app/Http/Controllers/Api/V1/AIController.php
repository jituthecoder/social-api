<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\ApiResponseTrait;
use App\Models\Workspace;
use App\Services\Contracts\AIServiceInterface;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AIController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        protected AIServiceInterface $aiService,
        protected SubscriptionService $subscriptionService
    ) {}

    protected function resolveWorkspace(Request $request): Workspace
    {
        $workspaceId = $request->header('X-Workspace-Id') ?? $request->query('workspace_id');
        if ($workspaceId) {
            $workspace = Workspace::findOrFail($workspaceId);
            Gate::authorize('view', $workspace);
            return $workspace;
        }

        return $request->user()->workspaces()->firstOrFail();
    }

    public function generate(Request $request): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);

        if (!$this->subscriptionService->checkQuota($workspace, 'ai_generations')) {
            return $this->errorResponse('Workspace monthly AI generation limit reached.', [], 429);
        }

        $request->validate(['prompt' => 'required|string|max:1000']);

        $result = $this->aiService->generatePost(
            $workspace,
            $request->user(),
            $request->input('prompt')
        );

        return $this->successResponse($result, 'AI content generated successfully');
    }

    public function rewrite(Request $request): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);

        if (!$this->subscriptionService->checkQuota($workspace, 'ai_generations')) {
            return $this->errorResponse('Workspace monthly AI generation limit reached.', [], 429);
        }

        $request->validate([
            'content' => 'required|string',
            'tone' => 'nullable|string|in:professional,casual,witty,urgent,inspiring',
        ]);

        $result = $this->aiService->rewritePost(
            $workspace,
            $request->user(),
            $request->input('content'),
            $request->input('tone', 'professional')
        );

        return $this->successResponse($result, 'Post rewritten successfully');
    }

    public function variant(Request $request): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);

        if (!$this->subscriptionService->checkQuota($workspace, 'ai_generations')) {
            return $this->errorResponse('Workspace monthly AI generation limit reached.', [], 429);
        }

        $request->validate([
            'content' => 'required|string',
            'platform' => 'required|string',
        ]);

        $result = $this->aiService->generatePlatformVariant(
            $workspace,
            $request->user(),
            $request->input('content'),
            $request->input('platform')
        );

        return $this->successResponse($result, 'Platform variant generated successfully');
    }

    public function hashtags(Request $request): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);

        $request->validate([
            'content' => 'required|string',
            'count' => 'nullable|integer|min:1|max:20',
        ]);

        $result = $this->aiService->generateHashtags(
            $workspace,
            $request->user(),
            $request->input('content'),
            $request->input('count', 5)
        );

        return $this->successResponse($result, 'Hashtags generated successfully');
    }

    public function ideas(Request $request): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);

        $request->validate([
            'topic' => 'required|string|max:255',
            'count' => 'nullable|integer|min:1|max:10',
        ]);

        $result = $this->aiService->generateContentIdeas(
            $workspace,
            $request->user(),
            $request->input('topic'),
            $request->input('count', 5)
        );

        return $this->successResponse($result, 'Content ideas generated successfully');
    }
}
