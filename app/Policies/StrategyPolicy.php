<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Strategy;
use App\Models\User;

class StrategyPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('strategies.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Strategy $strategy): bool
    {
        return $user->can('strategies.view');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('strategies.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Strategy $strategy): bool
    {
        return $user->can('strategies.update');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Strategy $strategy): bool
    {
        return $user->can('strategies.delete');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Strategy $strategy): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Strategy $strategy): bool
    {
        return false;
    }
}
