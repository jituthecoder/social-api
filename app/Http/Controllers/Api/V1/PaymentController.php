<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\ApiResponseTrait;
use App\Models\SubscriptionPlan;
use App\Models\Workspace;
use App\Services\RazorpayService;
use App\Services\UsageService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PaymentController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        protected RazorpayService $razorpayService,
        protected UsageService $usageService
    ) {}

    protected function resolveWorkspace(Request $request): Workspace
    {
        $workspaceId = $request->header('X-Workspace-Id') ?? $request->query('workspace_id') ?? $request->input('workspace_id');
        if ($workspaceId) {
            $workspace = Workspace::findOrFail($workspaceId);
            Gate::authorize('view', $workspace);
            return $workspace;
        }

        return $request->user()->workspaces()->firstOrFail();
    }

    public function createOrder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'exists:subscription_plans,id'],
            'billing_cycle' => ['sometimes', 'string', 'in:monthly,yearly'],
        ]);

        $workspace = $this->resolveWorkspace($request);
        $user = $request->user();
        $plan = SubscriptionPlan::findOrFail($validated['plan_id']);
        $billingCycle = $validated['billing_cycle'] ?? 'monthly';

        try {
            $orderData = $this->razorpayService->createOrder($workspace, $user, $plan, $billingCycle);
            return $this->successResponse($orderData, 'Razorpay order created successfully', 201);
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    public function verifyPayment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'razorpay_order_id' => ['required', 'string'],
            'razorpay_payment_id' => ['required', 'string'],
            'razorpay_signature' => ['required', 'string'],
        ]);

        try {
            $subscription = $this->razorpayService->verifyAndProcessPayment(
                $validated['razorpay_order_id'],
                $validated['razorpay_payment_id'],
                $validated['razorpay_signature']
            );

            $workspace = $subscription->workspace;
            $usageSummary = $this->usageService->getWorkspaceUsageSummary($workspace);

            return $this->successResponse([
                'subscription' => $subscription,
                'usage' => $usageSummary,
            ], 'Payment verified successfully and workspace plan upgraded');
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    public function history(Request $request): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);
        $payments = $workspace->payments()
            ->with(['plan:id,name,slug', 'user:id,name,email'])
            ->latest()
            ->paginate(15);

        return $this->successResponse($payments, 'Payment history retrieved successfully');
    }

    public function handleWebhook(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        $signatureHeader = $request->header('X-Razorpay-Signature');
        $payload = $request->all();

        $success = $this->razorpayService->handleWebhook($payload, $rawBody, $signatureHeader);

        if (!$success) {
            return response()->json(['status' => 'error', 'message' => 'Invalid webhook signature'], 400);
        }

        return response()->json(['status' => 'success']);
    }
}
