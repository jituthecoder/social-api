<?php

namespace App\Http\Controllers;

use App\Models\OauthState;
use App\Services\SocialProviders\AbstractSocialProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Handles browser-redirect OAuth callbacks from social platforms.
 *
 * Flow: Platform redirects here with ?code=&state= → exchange tokens →
 *       create SocialAccount → redirect browser to dashboard.
 */
class OAuthController extends Controller
{
    /**
     * Process the OAuth callback from a social platform.
     */
    public function callback(Request $request, string $platform)
    {
        $code       = $request->query('code');
        $stateToken = $request->query('state');
        $error      = $request->query('error');

        // Handle user-denied authorization
        if ($error) {
            return $this->redirectToDashboard($platform, error: $request->query('error_description', 'Authorization was denied.'));
        }

        if (!$code || !$stateToken) {
            return $this->redirectToDashboard($platform, error: 'Missing authorization code or state parameter.');
        }

        // Validate the CSRF state token
        $oauthState = OauthState::where('state_token', $stateToken)
            ->where('expires_at', '>', now())
            ->first();

        if (!$oauthState) {
            return $this->redirectToDashboard($platform, error: 'Invalid or expired OAuth state. Please try again.');
        }

        try {
            $provider = $this->resolveProvider($platform);

            if (!$provider) {
                $oauthState->delete();
                return $this->redirectToDashboard($platform, error: "Platform '{$platform}' is not supported.");
            }

            $redirectUri = config("services.{$platform}.redirect_uri");

            $account = $provider->connect([
                'code'         => $code,
                'redirect_uri' => $redirectUri,
                'workspace_id' => $oauthState->workspace_id,
                'state'        => $stateToken,
            ]);

            $oauthState->delete();

            return $this->redirectToDashboard($platform, accountId: $account->id);
        } catch (\Exception $e) {
            Log::error("OAuth callback failed for {$platform}", [
                'message' => $e->getMessage(),
                'state'   => $stateToken,
            ]);

            $oauthState->delete();

            return $this->redirectToDashboard($platform, error: 'Connection failed: ' . \Illuminate\Support\Str::limit($e->getMessage(), 150));
        }
    }

    /**
     * Resolve the social provider class for a given platform.
     */
    protected function resolveProvider(string $platform): ?AbstractSocialProvider
    {
        $class = config('services.social_providers.' . strtolower($platform));

        return $class ? app($class) : null;
    }

    /**
     * Redirect the browser back to the dashboard social accounts page.
     */
    protected function redirectToDashboard(string $platform, ?int $accountId = null, ?string $error = null)
    {
        $dashboardUrl = config('services.dashboard_url', 'http://localhost:5173');
        $params       = ['platform' => $platform];

        if ($accountId) {
            $params['connected']  = $platform;
            $params['account_id'] = $accountId;
        }

        if ($error) {
            $params['error'] = $error;
        }

        return redirect($dashboardUrl . '/dashboard/social-accounts?' . http_build_query($params));
    }
}
