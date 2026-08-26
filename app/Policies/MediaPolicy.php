<?php

namespace App\Policies;

use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;

class MediaPolicy
{
    public function view(User $user, Media $media, Workspace $workspace): bool
    {
        if ($media->workspace_id !== $workspace->id) {
            return false;
        }

        return $workspace->users()->where('users.id', $user->id)->exists();
    }

    public function delete(User $user, Media $media, Workspace $workspace): bool
    {
        if ($media->workspace_id !== $workspace->id) {
            return false;
        }

        return $workspace->users()
            ->where('users.id', $user->id)
            ->whereIn('role', ['owner', 'admin', 'editor'])
            ->exists();
    }
}
