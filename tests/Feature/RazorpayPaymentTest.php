<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\WorkspaceService;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RazorpayPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SubscriptionPlanSeeder::class);
    }

    public function test_authenticated_user_can_create_razorpay_order_for_monthly_plan(): void
    {
        $workspaceService = app(WorkspaceService::class);
        $user = User::factory()->create();
        $workspace = $workspaceService->createWorkspace($user, ['name' => 'Test Workspace']);

        $proPlan = SubscriptionPlan::where('slug', 'pro')->firstOrFail();

        $response = $this->actingAs($user)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/v1/payments/razorpay/create-order', [
                'plan_id' => $proPlan->id,
                'billing_cycle' => 'monthly',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.billing_cycle', 'monthly')
            ->assertJsonPath('data.amount_formatted', '29.00')
            ->assertJsonPath('data.amount', 2900);

        $this->assertDatabaseHas('payments', [
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'subscription_plan_id' => $proPlan->id,
            'amount' => 29.00,
            'billing_cycle' => 'monthly',
            'status' => 'created',
        ]);
    }

    public function test_authenticated_user_can_create_razorpay_order_for_yearly_plan(): void
    {
        $workspaceService = app(WorkspaceService::class);
        $user = User::factory()->create();
        $workspace = $workspaceService->createWorkspace($user, ['name' => 'Yearly Workspace']);

        $agencyPlan = SubscriptionPlan::where('slug', 'agency')->firstOrFail();

        $response = $this->actingAs($user)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/v1/payments/razorpay/create-order', [
                'plan_id' => $agencyPlan->id,
                'billing_cycle' => 'yearly',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.billing_cycle', 'yearly')
            ->assertJsonPath('data.amount_formatted', '990.00')
            ->assertJsonPath('data.amount', 99000);

        $this->assertDatabaseHas('payments', [
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'subscription_plan_id' => $agencyPlan->id,
            'amount' => 990.00,
            'billing_cycle' => 'yearly',
            'status' => 'created',
        ]);
    }

    public function test_verifying_payment_signature_upgrades_workspace_subscription(): void
    {
        $workspaceService = app(WorkspaceService::class);
        $user = User::factory()->create();
        $workspace = $workspaceService->createWorkspace($user, ['name' => 'Upgrade Workspace']);

        $proPlan = SubscriptionPlan::where('slug', 'pro')->firstOrFail();

        $orderResponse = $this->actingAs($user)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/v1/payments/razorpay/create-order', [
                'plan_id' => $proPlan->id,
                'billing_cycle' => 'monthly',
            ]);

        $orderId = $orderResponse->json('data.order_id');
        $paymentId = 'pay_mock_' . uniqid();
        $signature = 'mock_valid_signature';

        $verifyResponse = $this->actingAs($user)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/v1/payments/razorpay/verify', [
                'razorpay_order_id' => $orderId,
                'razorpay_payment_id' => $paymentId,
                'razorpay_signature' => $signature,
            ]);

        $verifyResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.subscription.subscription_plan_id', $proPlan->id)
            ->assertJsonPath('data.subscription.status', 'active');

        $this->assertDatabaseHas('subscriptions', [
            'workspace_id' => $workspace->id,
            'subscription_plan_id' => $proPlan->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
        ]);

        $this->assertDatabaseHas('payments', [
            'razorpay_order_id' => $orderId,
            'razorpay_payment_id' => $paymentId,
            'status' => 'paid',
        ]);
    }

    public function test_payment_history_returns_workspace_payments(): void
    {
        $workspaceService = app(WorkspaceService::class);
        $user = User::factory()->create();
        $workspace = $workspaceService->createWorkspace($user, ['name' => 'History Workspace']);
        $proPlan = SubscriptionPlan::where('slug', 'pro')->firstOrFail();

        Payment::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'subscription_plan_id' => $proPlan->id,
            'razorpay_order_id' => 'order_test_123',
            'razorpay_payment_id' => 'pay_test_123',
            'amount' => 29.00,
            'currency' => 'INR',
            'billing_cycle' => 'monthly',
            'status' => 'paid',
        ]);

        $response = $this->actingAs($user)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson('/api/v1/payments/history');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.razorpay_order_id', 'order_test_123');
    }
}
