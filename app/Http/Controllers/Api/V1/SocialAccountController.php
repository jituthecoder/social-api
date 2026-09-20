<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\ApiResponseTrait;
use App\Models\OauthState;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\SocialProviders\AbstractSocialProvider;
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

    /**
     * Initialize an OAuth flow for a given platform.
     *
     * Generates a CSRF state token, stores it in oauth_states,
     * and returns the platform-specific OAuth authorization URL.
     */
    public function connect(Request $request, string $platform): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);
        $platform  = strtolower($platform);
        $provider  = $this->resolveProvider($platform);

        if (!$provider) {
            return $this->errorResponse("Platform '{$platform}' is not supported.", [], 400);
        }

        $stateToken = Str::random(40);

        OauthState::create([
            'workspace_id' => $workspace->id,
            'user_id'      => $request->user()->id,
            'platform'     => $platform,
            'state_token'  => $stateToken,
            'expires_at'   => now()->addMinutes(15),
        ]);

        $redirectUri = config("services.{$platform}.redirect_uri");
        $authUrl     = $provider->getAuthorizationUrl($stateToken, $redirectUri);

        return $this->successResponse([
            'platform'     => $platform,
            'state'        => $stateToken,
            'redirect_url' => $authUrl,
        ], 'OAuth flow initialized');
    }

    /**
     * Handle an OAuth callback via API (JSON response).
     *
     * Browser-based callbacks are handled by OAuthController instead.
     * This endpoint supports AJAX/programmatic OAuth flows.
     */
    public function callback(Request $request, string $platform): JsonResponse
    {
        $stateToken = $request->query('state');
        $code       = $request->query('code');

        $oauthState = OauthState::where('state_token', $stateToken)
            ->where('expires_at', '>', now())
            ->first();

        if (!$oauthState) {
            return $this->errorResponse('Invalid or expired OAuth state token', [], 400);
        }

        $provider = $this->resolveProvider(strtolower($platform));

        if (!$provider || !$code) {
            $oauthState->delete();
            return $this->errorResponse('Missing authorization code or unsupported platform.', [], 400);
        }

        try {
            $redirectUri = config("services.{$platform}.redirect_uri");

            $account = $provider->connect([
                'code'         => $code,
                'redirect_uri' => $redirectUri,
                'workspace_id' => $oauthState->workspace_id,
                'state'        => $stateToken,
            ]);

            $oauthState->delete();

            return $this->successResponse($account, 'Social account connected successfully');
        } catch (\Exception $e) {
            $oauthState->delete();

            return $this->errorResponse('Failed to connect social account: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * Resolve a social provider instance from the config registry.
     */
    protected function resolveProvider(string $platform): ?AbstractSocialProvider
    {
        $class = config('services.social_providers.' . $platform);

        return $class ? app($class) : null;
    }
}

