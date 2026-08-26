<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\ApiResponseTrait;
use App\Models\OauthState;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class SocialAccountController extends Controller
{
    use ApiResponseTrait;

    protected function resolveWorkspace(Request $request): Workspace
    {
        $workspaceId = $request->header('X-Workspace-Id') ?? $request->query('workspace_id');
        if ($workspaceId) {
            $workspace = Workspace::findOrFail($workspaceId);
            Gate::authorize('view', $workspace);
            return $workspace;
        }

        return $request->user()->workspaces()->firstOrFail();
    }

    public function index(Request $request): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);
        $accounts = SocialAccount::where('workspace_id', $workspace->id)
            ->select(['id', 'workspace_id', 'platform', 'platform_account_id', 'name', 'username', 'account_type', 'avatar_url', 'connection_status', 'created_at'])
            ->get();

        return $this->successResponse($accounts, 'Social accounts retrieved');
    }

    public function show(Request $request, SocialAccount $socialAccount): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);
        Gate::authorize('view', [$socialAccount, $workspace]);

        // Tokens are automatically hidden by Eloquent model $hidden array
        return $this->successResponse($socialAccount->load('metadata'), 'Social account details retrieved');
    }

    public function destroy(Request $request, SocialAccount $socialAccount): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);
        Gate::authorize('disconnect', [$socialAccount, $workspace]);

        $socialAccount->delete();

        return $this->successResponse(null, 'Social account disconnected successfully');
    }

    public function connect(Request $request, string $platform): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);
        $stateToken = Str::random(40);

        OauthState::create([
            'workspace_id' => $workspace->id,
            'user_id' => $request->user()->id,
            'platform' => strtolower($platform),
            'state_token' => $stateToken,
            'expires_at' => now()->addMinutes(15),
        ]);

        // Generate redirect OAuth URL architecture for frontend
        $authUrl = config("services.{$platform}.redirect_url")
            ? "https://api.social.w3lead.in/oauth/{$platform}?state={$stateToken}"
            : "https://social-dashboard.w3lead.in/dashboard/social-accounts?connected=mock_{$platform}&state={$stateToken}";

        return $this->successResponse([
            'platform' => $platform,
            'state' => $stateToken,
            'redirect_url' => $authUrl,
        ], 'OAuth flow initialized');
    }

    public function callback(Request $request, string $platform): JsonResponse
    {
        $stateToken = $request->query('state');
        $oauthState = OauthState::where('state_token', $stateToken)
            ->where('expires_at', '>', now())
            ->first();

        if (!$oauthState) {
            return $this->errorResponse('Invalid or expired OAuth state token', [], 400);
        }

        // Mock connect architecture for testing during foundation stage
        $account = SocialAccount::updateOrCreate(
            [
                'workspace_id' => $oauthState->workspace_id,
                'platform' => strtolower($platform),
                'platform_account_id' => 'mock_acc_' . Str::random(8),
            ],
            [
                'name' => ucfirst($platform) . ' Business Account',
                'username' => strtolower($platform) . '_user',
                'account_type' => 'page',
                'avatar_url' => 'https://ui-avatars.com/api/?name=' . $platform,
                'connection_status' => 'connected',
            ]
        );

        $account->token()->updateOrCreate(
            ['social_account_id' => $account->id],
            [
                'access_token' => 'enc_access_token_' . Str::random(32),
                'refresh_token' => 'enc_refresh_token_' . Str::random(32),
                'expires_at' => now()->addDays(60),
                'scopes' => 'read,write,publish',
            ]
        );

        $oauthState->delete();

        return $this->successResponse($account, 'Social account connected successfully');
    }
}
