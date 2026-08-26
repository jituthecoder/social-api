<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_post(): void
    {
        $workspaceService = app(WorkspaceService::class);
        $user = User::factory()->create();
        $workspace = $workspaceService->createWorkspace($user, ['name' => 'Demo Space']);

        $response = $this->actingAs($user)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/v1/posts', [
                'title' => 'New Launch Post',
                'content' => 'Exciting news coming soon!',
                'variants' => [
                    [
                        'platform' => 'linkedin',
                        'content' => 'Exciting news coming soon for professionals!',
                    ]
                ]
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'New Launch Post');

        $this->assertDatabaseHas('posts', [
            'workspace_id' => $workspace->id,
            'title' => 'New Launch Post',
        ]);
        $this->assertDatabaseHas('post_variants', [
            'platform' => 'linkedin',
        ]);
    }
}
