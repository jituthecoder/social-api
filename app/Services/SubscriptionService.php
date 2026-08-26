<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Workspace;

class SubscriptionService
{
    public function getActiveSubscription(Workspace $workspace): ?Subscription
    {
        return $workspace->subscription()->with('plan')->first();
    }

    public function checkQuota(Workspace $workspace, string $metric, int $requestedQuantity = 1): bool
    {
        $subscription = $this->getActiveSubscription($workspace);
        if (!$subscription || !$subscription->plan) {
            return false;
        }

        $limits = $subscription->plan->limits ?? [];
        if (!isset($limits[$metric])) {
            return true; // Unlimited if metric not constrained
        }

        $limit = (int) $limits[$metric];
        if ($limit < 0) {
            return true; // Negative represents unlimited tier
        }

        $usageService = app(UsageService::class);
        $currentUsage = $usageService->getMonthlyUsage($workspace, $metric);

        return ($currentUsage + $requestedQuantity) <= $limit;
    }
}
