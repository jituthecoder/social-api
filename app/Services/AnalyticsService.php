<?php

namespace App\Services;

use App\Models\AnalyticsDaily;
use App\Models\Workspace;

class AnalyticsService
{
    public function getWorkspaceAnalyticsSummary(Workspace $workspace, int $days = 30): array
    {
        $startDate = now()->subDays($days)->toDateString();

        $dailyMetrics = AnalyticsDaily::where('workspace_id', $workspace->id)
            ->where('date', '>=', $startDate)
            ->get();

        $totals = [
            'impressions' => (int) $dailyMetrics->sum('impressions'),
            'reach' => (int) $dailyMetrics->sum('reach'),
            'likes' => (int) $dailyMetrics->sum('likes'),
            'comments' => (int) $dailyMetrics->sum('comments'),
            'shares' => (int) $dailyMetrics->sum('shares'),
            'clicks' => (int) $dailyMetrics->sum('clicks'),
            'followers' => (int) $dailyMetrics->max('followers'),
        ];

        // Group by platform breakdown
        $platformBreakdown = $dailyMetrics->groupBy('platform')->map(function ($items, $platform) {
            return [
                'platform' => $platform,
                'impressions' => (int) $items->sum('impressions'),
                'likes' => (int) $items->sum('likes'),
                'comments' => (int) $items->sum('comments'),
                'shares' => (int) $items->sum('shares'),
            ];
        })->values();

        return [
            'period_days' => $days,
            'totals' => $totals,
            'platforms' => $platformBreakdown,
        ];
    }
}
