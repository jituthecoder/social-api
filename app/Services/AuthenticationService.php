<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use App\Models\SubscriptionPlan;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthenticationService
{
    public function __construct(
        protected AuditLogService $auditLogService,
        protected WorkspaceService $workspaceService
    ) {}

    public function register(array $data): array
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'password' => Hash::make($data['password']),
            'status' => 'active',
        ]);

        // Automatically create default primary workspace for new user
        $workspaceName = $data['workspace_name'] ?? ($user->name . "'s Workspace");
        $workspace = $this->workspaceService->createWorkspace($user, [
            'name' => $workspaceName,
            'timezone' => $data['timezone'] ?? 'UTC',
        ]);

        $token = $user->createToken('auth-token')->plainTextToken;

        $this->auditLogService->log('user.registered', $workspace, $user, ['email' => $user->email]);

        return [
            'user' => $user->load('workspaces'),
            'token' => $token,
            'current_workspace' => $workspace,
        ];
    }

    public function login(array $data): array
    {
        $user = User::where('email', strtolower($data['email']))->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials provided.'],
            ]);
        }

        if ($user->status !== 'active') {
            throw ValidationException::withMessages([
                'email' => ['Your account has been deactivated.'],
            ]);
        }

        $user->update(['last_login_at' => now()]);

        $token = $user->createToken('auth-token')->plainTextToken;

        $primaryWorkspace = $user->workspaces()->first();

        $this->auditLogService->log('user.login', $primaryWorkspace, $user);

        return [
            'user' => $user->load('workspaces'),
            'token' => $token,
            'current_workspace' => $primaryWorkspace,
        ];
    }

    public function logout(User $user): void
    {
        $this->auditLogService->log('user.logout', null, $user);
        $user->currentAccessToken()->delete();
    }
}
