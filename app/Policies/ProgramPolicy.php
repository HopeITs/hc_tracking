<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Program;
use App\Models\User;

/**
 * Programs mirror HR and are never created or deleted here; tracking only
 * assigns a program's strategy and manager, which stays with programs.update.
 */
class ProgramPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('programs.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Program $program): bool
    {
        return $user->can('programs.view');
    }

    /**
     * Determine whether the user can assign the program's strategy and manager.
     */
    public function update(User $user, Program $program): bool
    {
        return $user->can('programs.update');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Program $program): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Program $program): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Program $program): bool
    {
        return false;
    }
}
