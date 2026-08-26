<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\ApiResponseTrait;
use App\Models\SubscriptionPlan;
use App\Models\Workspace;
use App\Services\SubscriptionService;
use App\Services\UsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SubscriptionController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        protected SubscriptionService $subscriptionService,
        protected UsageService $usageService
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

    public function plans(): JsonResponse
    {
        $plans = SubscriptionPlan::where('status', 'active')->get();

        return $this->successResponse($plans, 'Subscription plans retrieved');
    }

    public function usage(Request $request): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);
        $subscription = $this->subscriptionService->getActiveSubscription($workspace);
        $usageSummary = $this->usageService->getWorkspaceUsageSummary($workspace);

        return $this->successResponse([
            'subscription' => $subscription,
            'usage' => $usageSummary,
        ], 'Workspace subscription and usage details retrieved');
    }
}
