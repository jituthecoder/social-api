<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_a_cannot_access_user_b_workspace(): void
    {
        $workspaceService = app(WorkspaceService::class);

        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $workspaceB = $workspaceService->createWorkspace($userB, ['name' => 'Workspace B']);

        $response = $this->actingAs($userA)->getJson("/api/v1/workspaces/{$workspaceB->id}");

        $response->assertStatus(403);
    }

    public function test_user_a_cannot_access_user_b_posts(): void
    {
        $workspaceService = app(WorkspaceService::class);

        $userA = User::factory()->create();
        $workspaceA = $workspaceService->createWorkspace($userA, ['name' => 'Workspace A']);

        $userB = User::factory()->create();
        $workspaceB = $workspaceService->createWorkspace($userB, ['name' => 'Workspace B']);

        $postB = Post::create([
            'workspace_id' => $workspaceB->id,
            'user_id' => $userB->id,
            'content' => 'Private post for B',
            'status' => 'draft',
        ]);

        $response = $this->actingAs($userA)
            ->withHeader('X-Workspace-Id', $workspaceA->id)
            ->getJson("/api/v1/posts/{$postB->id}");

        $response->assertStatus(403);
    }

    public function test_user_a_cannot_disconnect_user_b_social_account(): void
    {
        $workspaceService = app(WorkspaceService::class);

        $userA = User::factory()->create();
        $workspaceA = $workspaceService->createWorkspace($userA, ['name' => 'Workspace A']);

        $userB = User::factory()->create();
        $workspaceB = $workspaceService->createWorkspace($userB, ['name' => 'Workspace B']);

        $accountB = SocialAccount::create([
            'workspace_id' => $workspaceB->id,
            'platform' => 'linkedin',
            'platform_account_id' => 'acc_b_123',
            'name' => 'LinkedIn B',
        ]);

        $response = $this->actingAs($userA)
            ->withHeader('X-Workspace-Id', $workspaceA->id)
            ->deleteJson("/api/v1/social-accounts/{$accountB->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('social_accounts', ['id' => $accountB->id]);
    }
}
