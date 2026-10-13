<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ProposalStatus;
use App\Models\Proposal;
use App\Models\User;

class ProposalPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('proposals.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Proposal $proposal): bool
    {
        return $user->can('proposals.view');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('proposals.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Proposal $proposal): bool
    {
        return $user->can('proposals.update');
    }

    /**
     * Determine whether the user can move the proposal to a status.
     *
     * The transition table, not this ability, decides which moves exist.
     */
    public function transition(User $user, Proposal $proposal, ProposalStatus $to): bool
    {
        return $user->can('proposals.update');
    }

    /**
     * Determine whether the user can correct a rejected or completed status.
     */
    public function correctStatus(User $user, Proposal $proposal): bool
    {
        return $user->can('proposals.correct-status');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Proposal $proposal): bool
    {
        return $user->can('proposals.delete');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Proposal $proposal): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Proposal $proposal): bool
    {
        return false;
    }
}
