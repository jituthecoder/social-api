<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SocialAccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_tokens_are_encrypted_in_database_and_hidden_from_api(): void
    {
        $workspaceService = app(WorkspaceService::class);
        $user = User::factory()->create();
        $workspace = $workspaceService->createWorkspace($user, ['name' => 'Security Space']);

        $account = SocialAccount::create([
            'workspace_id' => $workspace->id,
            'platform' => 'x',
            'platform_account_id' => 'x_123',
            'name' => 'Test Account',
        ]);

        $rawTokenSecret = 'secret_access_token_12345';

        $account->token()->create([
            'access_token' => $rawTokenSecret,
            'refresh_token' => 'secret_refresh_token_67890',
            'expires_at' => now()->addDays(30),
        ]);

        // 1. Verify encrypted at rest in raw SQL DB
        $dbRawRow = DB::table('social_account_tokens')
            ->where('social_account_id', $account->id)
            ->first();

        $this->assertNotEquals($rawTokenSecret, $dbRawRow->access_token);

        // 2. Verify API response hides tokens completely
        $response = $this->actingAs($user)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson("/api/v1/social-accounts/{$account->id}");

        $response->assertStatus(200)
            ->assertDontSee($rawTokenSecret)
            ->assertJsonMissing(['access_token', 'refresh_token']);
    }
}
