<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\UserTodo;

/**
 * A to-do belongs to one user: only they may see or change it, whatever
 * their role. Anyone signed in keeps a list of their own.
 */
class UserTodoPolicy
{
    /**
     * Determine whether the user can list their own to-dos.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the to-do.
     */
    public function view(User $user, UserTodo $todo): bool
    {
        return $this->owns($user, $todo);
    }

    /**
     * Determine whether the user can add a to-do to their own list.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can edit, tick or move the to-do.
     */
    public function update(User $user, UserTodo $todo): bool
    {
        return $this->owns($user, $todo);
    }

    /**
     * Determine whether the user can remove the to-do.
     */
    public function delete(User $user, UserTodo $todo): bool
    {
        return $this->owns($user, $todo);
    }

    private function owns(User $user, UserTodo $todo): bool
    {
        return $todo->user_id === $user->id;
    }
}
