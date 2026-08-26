<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Support\Str;

class WorkspaceService
{
    public function __construct(
        protected AuditLogService $auditLogService
    ) {}

    public function createWorkspace(User $owner, array $data): Workspace
    {
        $slug = Str::slug($data['name']);
        $originalSlug = $slug;
        $counter = 1;

        while (Workspace::where('slug', $slug)->exists()) {
            $slug = $originalSlug . '-' . $counter++;
        }

        $workspace = Workspace::create([
            'name' => $data['name'],
            'slug' => $slug,
            'owner_id' => $owner->id,
            'status' => 'active',
            'timezone' => $data['timezone'] ?? 'UTC',
            'settings' => $data['settings'] ?? [],
        ]);

        // Attach owner in workspace_users pivot table
        $workspace->users()->attach($owner->id, [
            'role' => 'owner',
            'status' => 'active',
        ]);

        // Assign default Free subscription plan if available
        $freePlan = SubscriptionPlan::where('slug', 'free')->first();
        if ($freePlan) {
            Subscription::create([
                'workspace_id' => $workspace->id,
                'subscription_plan_id' => $freePlan->id,
                'status' => 'active',
            ]);
        }

        $this->auditLogService->log('workspace.created', $workspace, $owner);

        return $workspace;
    }

    public function addMember(Workspace $workspace, User $user, string $role = 'editor'): WorkspaceUser
    {
        $membership = WorkspaceUser::updateOrCreate(
            [
                'workspace_id' => $workspace->id,
                'user_id' => $user->id,
            ],
            [
                'role' => $role,
                'status' => 'active',
            ]
        );

        $this->auditLogService->log('workspace.member_added', $workspace, auth()->user(), [
            'added_user_id' => $user->id,
            'role' => $role,
        ]);

        return $membership;
    }

    public function removeMember(Workspace $workspace, User $user): bool
    {
        if ($workspace->owner_id === $user->id) {
            throw new \InvalidArgumentException("Cannot remove workspace owner.");
        }

        $detached = $workspace->users()->detach($user->id);

        $this->auditLogService->log('workspace.member_removed', $workspace, auth()->user(), [
            'removed_user_id' => $user->id,
        ]);

        return $detached > 0;
    }
}
