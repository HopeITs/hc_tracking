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
        return $user->can('proposals.view') || $this->isReviewer($user, $proposal) || $this->ownsAProject($user, $proposal);
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
        return $user->can('proposals.update') || $this->ownsAProject($user, $proposal);
    }

    /**
     * Determine whether the user can move the proposal to a status.
     *
     * The transition table, not this ability, decides which moves exist.
     */
    public function transition(User $user, Proposal $proposal, ProposalStatus $to): bool
    {
        return $user->can('proposals.update') || $this->ownsAProject($user, $proposal);
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
     * Determine whether the user can record the donor's approval (D-70): only
     * the holders of proposals.approve, which is the Public Relations Manager.
     */
    public function approve(User $user, Proposal $proposal): bool
    {
        return $user->can('proposals.approve');
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
     * Whether the user owns a project the proposal funds: the project owner
     * is its project manager and the proposal's owner (contracts §15.2), so
     * the steps the project page puts on them are theirs to do (user
     * decision 2026-11-12). Recording the donor's decision stays with
     * proposals.approve (D-70).
     */
    private function ownsAProject(User $user, Proposal $proposal): bool
    {
        if ($user->employee_id === null || $proposal->getKey() === null) {
            return false;
        }

        $proposalId = (int) $proposal->getKey();

        return in_array((int) $user->employee_id, $this->ownerIds($proposalId), true);
    }

    /**
     * The owner employee ids of a proposal's projects, read once per request
     * (a page asks the policy once per listed project).
     *
     * @return list<int>
     */
    private function ownerIds(int $proposalId): array
    {
        static $cache = [];
        $request = spl_object_id(request());

        return $cache[$request][$proposalId] ??= array_values(Proposal::ownerEmployeeIdsFor([$proposalId])[$proposalId] ?? []);
    }

    /**
     * Whether the user's HR employee reviews any stage of the proposal.
     * Read on every request, never cached on a component. A list that eager
     * loads `reviewers` (narrowed to the viewer's rows is enough) is read from
     * memory, so checking each card costs no query.
     */
    private function isReviewer(User $user, Proposal $proposal): bool
    {
        if ($user->employee_id === null) {
            return false;
        }

        if ($proposal->relationLoaded('reviewers')) {
            return $proposal->reviewers->contains('employee_id', $user->employee_id);
        }

        return $proposal->reviewers()->where('employee_id', $user->employee_id)->exists();
    }
}
