<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\Workspace;
use Exception;
use Illuminate\Support\Facades\Log;
use Razorpay\Api\Api;
use Razorpay\Api\Errors\SignatureVerificationError;

class RazorpayService
{
    protected string $keyId;
    protected string $keySecret;
    protected string $currency;

    public function __construct()
    {
        $this->keyId = config('razorpay.key_id', '');
        $this->keySecret = config('razorpay.key_secret', '');
        $this->currency = config('razorpay.currency', 'INR');
    }

    protected function getRazorpayApi(): Api
    {
        return new Api($this->keyId, $this->keySecret);
    }

    public function createOrder(Workspace $workspace, User $user, SubscriptionPlan $plan, string $billingCycle = 'monthly'): array
    {
        $price = $billingCycle === 'yearly' ? (float) $plan->price_yearly : (float) $plan->price_monthly;
        
        if ($price <= 0) {
            throw new Exception('Cannot create Razorpay order for free plan.');
        }

        // Razorpay expects amount in smallest currency subunit (e.g. paise / cents)
        $amountInSubunits = (int) round($price * 100);

        $orderData = [
            'receipt' => 'rcpt_ws_' . $workspace->id . '_' . time(),
            'amount' => $amountInSubunits,
            'currency' => $this->currency,
            'notes' => [
                'workspace_id' => $workspace->id,
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'billing_cycle' => $billingCycle,
            ],
        ];

        if (!empty($this->keyId) && !empty($this->keySecret)) {
            $api = $this->getRazorpayApi();
            $razorpayOrder = $api->order->create($orderData);
            $orderId = $razorpayOrder['id'];
        } else {
            // Fallback for local testing when keys are not configured yet
            $orderId = 'order_mock_' . uniqid();
        }

        $payment = Payment::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'razorpay_order_id' => $orderId,
            'amount' => $price,
            'currency' => $this->currency,
            'billing_cycle' => $billingCycle,
            'status' => 'created',
            'meta' => $orderData['notes'],
        ]);

        return [
            'order_id' => $orderId,
            'razorpay_key' => $this->keyId,
            'amount' => $amountInSubunits,
            'amount_formatted' => number_format($price, 2),
            'currency' => $this->currency,
            'plan' => [
                'id' => $plan->id,
                'name' => $plan->name,
                'slug' => $plan->slug,
            ],
            'billing_cycle' => $billingCycle,
            'payment_id' => $payment->id,
        ];
    }

    public function verifyAndProcessPayment(string $orderId, string $paymentId, string $signature, bool $skipSignatureVerification = false): Subscription
    {
        $payment = Payment::where('razorpay_order_id', $orderId)->first();
        if (!$payment) {
            throw new Exception("Invalid order ID. No payment record found for order_id: {$orderId}");
        }

        // Verify Razorpay HMAC signature if credentials exist and not skipped (e.g. from verified webhook)
        if (!$skipSignatureVerification && !empty($this->keySecret) && !str_starts_with($orderId, 'order_mock_')) {
            $expectedSignature = hash_hmac('sha256', $orderId . '|' . $paymentId, $this->keySecret);
            if (!hash_equals($expectedSignature, $signature)) {
                $payment->update(['status' => 'failed']);
                throw new Exception('Razorpay payment signature verification failed.');
            }
        }

        // Update payment record
        $payment->update([
            'razorpay_payment_id' => $paymentId,
            'razorpay_signature' => $signature,
            'status' => 'paid',
        ]);

        $workspace = $payment->workspace;
        $plan = $payment->plan;
        $endsAt = $payment->billing_cycle === 'yearly' ? now()->addYear() : now()->addMonth();

        // Update active subscription
        $subscription = Subscription::updateOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'subscription_plan_id' => $plan->id,
                'billing_cycle' => $payment->billing_cycle,
                'status' => 'active',
                'ends_at' => $endsAt,
                'razorpay_order_id' => $orderId,
                'razorpay_payment_id' => $paymentId,
            ]
        );

        // Audit Log entry
        AuditLog::create([
            'workspace_id' => $workspace->id,
            'user_id' => $payment->user_id,
            'action' => 'subscription_change',
            'details' => [
                'event' => 'razorpay_payment_success',
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'billing_cycle' => $payment->billing_cycle,
                'amount' => $payment->amount,
                'order_id' => $orderId,
                'payment_id' => $paymentId,
            ],
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return $subscription->load('plan');
    }

    public function handleWebhook(array $payload, string $rawBody, ?string $signatureHeader): bool
    {
        $webhookSecret = config('razorpay.webhook_secret', '');

        if (!empty($webhookSecret) && !empty($signatureHeader)) {
            $expectedSignature = hash_hmac('sha256', $rawBody, $webhookSecret);
            if (!hash_equals($expectedSignature, $signatureHeader)) {
                Log::warning('Razorpay Webhook signature verification failed.');
                return false;
            }
        }

        $event = $payload['event'] ?? null;
        Log::info("Razorpay Webhook event received: {$event}");

        if ($event === 'order.paid' || $event === 'payment.captured') {
            $paymentPayload = $payload['payload']['payment']['entity'] ?? [];
            $orderId = $paymentPayload['order_id'] ?? null;
            $paymentId = $paymentPayload['id'] ?? null;

            if ($orderId && $paymentId) {
                $payment = Payment::where('razorpay_order_id', $orderId)->first();
                if ($payment && $payment->status !== 'paid') {
                    $this->verifyAndProcessPayment(
                        $orderId,
                        $paymentId,
                        $paymentPayload['signature'] ?? 'webhook_verified',
                        true
                    );
                }
            }
        }

        return true;
    }
}
