<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ProposalPlanShift;
use App\Models\User;

/**
 * The approval-rebase ledger is written by the system only
 * (RebaseProposalPlan); people may read it wherever they may read proposals.
 */
class ProposalPlanShiftPolicy
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
    public function view(User $user, ProposalPlanShift $proposalPlanShift): bool
    {
        return $user->can('proposals.view');
    }

    /**
     * Nobody creates a shift by hand.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Nobody edits a shift.
     */
    public function update(User $user, ProposalPlanShift $proposalPlanShift): bool
    {
        return false;
    }

    /**
     * Nobody deletes a shift; it goes with its proposal.
     */
    public function delete(User $user, ProposalPlanShift $proposalPlanShift): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, ProposalPlanShift $proposalPlanShift): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, ProposalPlanShift $proposalPlanShift): bool
    {
        return false;
    }
}
