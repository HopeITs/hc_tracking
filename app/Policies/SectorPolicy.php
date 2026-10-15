<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Sector;
use App\Models\User;

/**
 * Sectors are visible to everyone and maintained by MEAL and the General
 * Manager (sectors.view / sectors.manage, contracts.md §8). Deactivating,
 * reactivating and moving a sector are edits.
 */
class SectorPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('sectors.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Sector $sector): bool
    {
        return $user->can('sectors.view');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('sectors.manage');
    }

    /**
     * Determine whether the user can update, deactivate, reactivate or move the model.
     */
    public function update(User $user, Sector $sector): bool
    {
        return $user->can('sectors.manage');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Sector $sector): bool
    {
        return $user->can('sectors.manage');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Sector $sector): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Sector $sector): bool
    {
        return false;
    }
}
