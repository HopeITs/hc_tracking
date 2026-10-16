<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StrategicPillar;
use App\Models\User;

/**
 * Pillars are part of their strategy: visible with `strategies.view`,
 * created, edited, deleted and assigned with `strategies.update` (T32).
 */
class StrategicPillarPolicy
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
    public function view(User $user, StrategicPillar $strategicPillar): bool
    {
        return $user->can('strategies.view');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('strategies.update');
    }

    /**
     * Determine whether the user can update the model or assign programs to it.
     */
    public function update(User $user, StrategicPillar $strategicPillar): bool
    {
        return $user->can('strategies.update');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, StrategicPillar $strategicPillar): bool
    {
        return $user->can('strategies.update');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, StrategicPillar $strategicPillar): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, StrategicPillar $strategicPillar): bool
    {
        return false;
    }
}
