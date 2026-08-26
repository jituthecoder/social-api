<?php

namespace App\Policies;

use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;

class PostPolicy
{
    public function view(User $user, Post $post, Workspace $workspace): bool
    {
        if ($post->workspace_id !== $workspace->id) {
            return false;
        }

        return $workspace->users()->where('users.id', $user->id)->exists();
    }

    public function create(User $user, Workspace $workspace): bool
    {
        return $workspace->users()
            ->where('users.id', $user->id)
            ->whereIn('role', ['owner', 'admin', 'editor'])
            ->exists();
    }

    public function update(User $user, Post $post, Workspace $workspace): bool
    {
        if ($post->workspace_id !== $workspace->id) {
            return false;
        }

        return $workspace->users()
            ->where('users.id', $user->id)
            ->whereIn('role', ['owner', 'admin', 'editor'])
            ->exists();
    }

    public function delete(User $user, Post $post, Workspace $workspace): bool
    {
        if ($post->workspace_id !== $workspace->id) {
            return false;
        }

        return $workspace->users()
            ->where('users.id', $user->id)
            ->whereIn('role', ['owner', 'admin', 'editor'])
            ->exists();
    }
}
