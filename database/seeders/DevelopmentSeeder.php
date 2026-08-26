<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DevelopmentSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::updateOrCreate(
            ['email' => 'admin@w3lead.in'],
            [
                'name' => 'Demo Admin',
                'password' => Hash::make('password123'),
                'email_verified_at' => now(),
                'status' => 'active',
            ]
        );

        $workspaceService = app(WorkspaceService::class);
        
        if ($user->ownedWorkspaces()->count() === 0) {
            $workspaceService->createWorkspace($user, [
                'name' => 'W3Lead Demo Workspace',
                'timezone' => 'UTC',
            ]);
        }
    }
}
