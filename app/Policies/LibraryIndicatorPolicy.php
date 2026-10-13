<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LibraryIndicator;
use App\Models\User;

/**
 * The Indicator Library is visible to everyone and maintained by MEAL and the
 * General Manager (indicator-library.*, contracts.md §8). Deactivating and
 * reactivating an entry is an edit.
 */
class LibraryIndicatorPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('indicator-library.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, LibraryIndicator $libraryIndicator): bool
    {
        return $user->can('indicator-library.view');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('indicator-library.create');
    }

    /**
     * Determine whether the user can update, deactivate or reactivate the model.
     */
    public function update(User $user, LibraryIndicator $libraryIndicator): bool
    {
        return $user->can('indicator-library.update');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, LibraryIndicator $libraryIndicator): bool
    {
        return $user->can('indicator-library.delete');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, LibraryIndicator $libraryIndicator): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, LibraryIndicator $libraryIndicator): bool
    {
        return false;
    }
}
