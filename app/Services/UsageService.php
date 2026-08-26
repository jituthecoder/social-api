<?php

namespace App\Services;

use App\Models\UsageRecord;
use App\Models\Workspace;

class UsageService
{
    public function recordUsage(Workspace $workspace, string $metric, int $quantity = 1, array $metadata = []): UsageRecord
    {
        $period = now()->format('Y-m');

        return UsageRecord::create([
            'workspace_id' => $workspace->id,
            'metric' => $metric,
            'quantity' => $quantity,
            'period' => $period,
            'metadata' => $metadata,
        ]);
    }

    public function getMonthlyUsage(Workspace $workspace, string $metric, ?string $period = null): int
    {
        $period = $period ?? now()->format('Y-m');

        return (int) UsageRecord::where('workspace_id', $workspace->id)
            ->where('metric', $metric)
            ->where('period', $period)
            ->sum('quantity');
    }

    public function getWorkspaceUsageSummary(Workspace $workspace): array
    {
        $period = now()->format('Y-m');

        return [
            'period' => $period,
            'ai_generations' => $this->getMonthlyUsage($workspace, 'ai_generations', $period),
            'posts_created' => $this->getMonthlyUsage($workspace, 'posts_created', $period),
            'posts_published' => $this->getMonthlyUsage($workspace, 'posts_published', $period),
            'social_accounts' => $workspace->socialAccounts()->where('connection_status', 'connected')->count(),
            'storage_bytes' => (int) $workspace->media()->sum('size'),
        ];
    }
}
