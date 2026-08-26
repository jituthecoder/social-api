<?php

namespace App\Policies;

use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;

class SocialAccountPolicy
{
    public function view(User $user, SocialAccount $socialAccount, Workspace $workspace): bool
    {
        if ($socialAccount->workspace_id !== $workspace->id) {
            return false;
        }

        return $workspace->users()->where('users.id', $user->id)->exists();
    }

    public function disconnect(User $user, SocialAccount $socialAccount, Workspace $workspace): bool
    {
        if ($socialAccount->workspace_id !== $workspace->id) {
            return false;
        }

        return $workspace->users()
            ->where('users.id', $user->id)
            ->whereIn('role', ['owner', 'admin'])
            ->exists();
    }
}
