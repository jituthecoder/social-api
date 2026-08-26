<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\ApiResponseTrait;
use App\Http\Requests\Workspace\CreateWorkspaceRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class WorkspaceController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        protected WorkspaceService $workspaceService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $workspaces = $request->user()->workspaces()->with(['owner', 'subscription.plan'])->get();

        return $this->successResponse($workspaces, 'Workspaces retrieved');
    }

    public function store(CreateWorkspaceRequest $request): JsonResponse
    {
        $workspace = $this->workspaceService->createWorkspace(
            $request->user(),
            $request->validated()
        );

        return $this->successResponse($workspace->load('users'), 'Workspace created successfully', 201);
    }

    public function show(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('view', $workspace);

        return $this->successResponse(
            $workspace->load(['users', 'socialAccounts', 'subscription.plan']),
            'Workspace details retrieved'
        );
    }

    public function update(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('update', $workspace);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'timezone' => 'sometimes|string|timezone',
            'settings' => 'sometimes|array',
        ]);

        $workspace->update($validated);

        return $this->successResponse($workspace, 'Workspace updated successfully');
    }

    public function addMember(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('manageMembers', $workspace);

        $validated = $request->validate([
            'email' => 'required|email|exists:users,email',
            'role' => 'required|in:owner,admin,editor,viewer',
        ]);

        $user = User::where('email', strtolower($validated['email']))->firstOrFail();
        $membership = $this->workspaceService->addMember($workspace, $user, $validated['role']);

        return $this->successResponse($membership, 'Team member added successfully');
    }

    public function removeMember(Request $request, Workspace $workspace, User $user): JsonResponse
    {
        Gate::authorize('manageMembers', $workspace);

        $this->workspaceService->removeMember($workspace, $user);

        return $this->successResponse(null, 'Member removed from workspace');
    }
}
