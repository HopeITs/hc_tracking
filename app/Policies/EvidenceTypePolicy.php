<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\EvidenceType;
use App\Models\User;

/**
 * Evidence types are MEAL's list (evidence-types.manage, contracts.md §8,
 * §12.4). There is no separate view permission: seeing the list and changing
 * it both need `evidence-types.manage`. Deactivating, reactivating and moving
 * a type are edits.
 */
class EvidenceTypePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('evidence-types.manage');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, EvidenceType $evidenceType): bool
    {
        return $user->can('evidence-types.manage');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('evidence-types.manage');
    }

    /**
     * Determine whether the user can update, deactivate, reactivate or move the model.
     */
    public function update(User $user, EvidenceType $evidenceType): bool
    {
        return $user->can('evidence-types.manage');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, EvidenceType $evidenceType): bool
    {
        return $user->can('evidence-types.manage');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, EvidenceType $evidenceType): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, EvidenceType $evidenceType): bool
    {
        return false;
    }
}
