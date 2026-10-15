<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ProposalStatus;
use App\Models\Proposal;
use App\Models\ProposalReviewer;
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
     * Determine whether the user can view the model: proposals.view holders,
     * and the people asked to review it (T07). Being a reviewer grants no
     * permission; the money on the page stays behind its own permissions.
     */
    public function view(User $user, Proposal $proposal): bool
    {
        return $user->can('proposals.view') || $this->isReviewer($user, $proposal);
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
     * Determine whether the user can add or remove reviewers.
     */
    public function manageReviewers(User $user, Proposal $proposal): bool
    {
        return $user->can('proposals.manage-reviewers');
    }

    /**
     * Determine whether the user can mark a review row as seen or edit its
     * note: only the row's own person. A user with no HR employee never
     * matches a row.
     */
    public function markReviewed(User $user, Proposal $proposal, ProposalReviewer $reviewer): bool
    {
        return $user->employee_id !== null
            && $reviewer->proposal_id === $proposal->id
            && $reviewer->employee_id === (int) $user->employee_id;
    }

    /**
     * Determine whether the user can attach a need assessment: proposal
     * editors, or a reviewer of this proposal who may create results (MEAL).
     */
    public function uploadNeedAssessment(User $user, Proposal $proposal): bool
    {
        return $user->can('proposals.update') || ($user->can('results.create') && $this->isReviewer($user, $proposal));
    }

    /**
     * Determine whether the user can record the donor's approval. There is no
     * proposals.approve permission: approval follows proposals.update.
     */
    public function approve(User $user, Proposal $proposal): bool
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

    /**
     * Whether the user's HR employee reviews any stage of the proposal.
     * Read on every request, never cached on a component.
     */
    private function isReviewer(User $user, Proposal $proposal): bool
    {
        return $user->employee_id !== null
            && $proposal->reviewers()->where('employee_id', $user->employee_id)->exists();
    }
}
