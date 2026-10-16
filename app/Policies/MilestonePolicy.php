<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ProposalPlanWindow;
use App\Models\Milestone;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Milestones follow their project: besides holders of the milestones.*
 * permissions, the project's owner and its program's manager (see
 * User::ownedProjectIds()) add and edit the milestones of projects they answer
 * for, and a milestone's responsible person moves its status and actual dates.
 */
class MilestonePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('milestones.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Milestone $milestone): bool
    {
        return $user->can('milestones.view');
    }

    /**
     * Determine whether the user can add a milestone — to the given project,
     * or (with no project yet chosen) to at least one project.
     */
    public function create(User $user, ?int $projectId = null): bool
    {
        if ($user->can('milestones.create')) {
            return true;
        }

        $ownedProjectIds = $user->ownedProjectIds();

        return $projectId === null ? $ownedProjectIds !== [] : in_array($projectId, $ownedProjectIds, true);
    }

    /**
     * Determine whether the user can edit every field of the milestone.
     */
    public function update(User $user, Milestone $milestone): bool
    {
        return $user->can('milestones.update') || in_array($milestone->project_id, $user->ownedProjectIds(), true);
    }

    /**
     * Determine whether the user can move the milestone's status and actual
     * dates: every editor, plus the milestone's responsible person.
     */
    public function updateProgress(User $user, Milestone $milestone): bool
    {
        return $this->update($user, $milestone)
            || ($user->employee_id !== null && $milestone->responsible_user_id === $user->employee_id);
    }

    /**
     * Determine whether the user can add a milestone to a proposal's plan,
     * or edit one ($milestone), in the proposal's current window (T31):
     * while planning, plan rights (proposals.update, or milestones.create
     * with sight of the proposal); after approval, normal execution rights.
     */
    public function planForProposal(User $user, Proposal $proposal, ?Milestone $milestone = null): Response
    {
        $allowed = match (ProposalPlanWindow::for($proposal->status)) {
            ProposalPlanWindow::Planning => $user->can('proposals.update')
                || ($user->can('milestones.create') && Gate::forUser($user)->allows('view', $proposal)),
            ProposalPlanWindow::Execution => $milestone === null
                ? $user->can('milestones.create')
                : $this->update($user, $milestone),
            ProposalPlanWindow::Locked, ProposalPlanWindow::Closed => false,
        };

        return $allowed ? Response::allow() : Response::deny(ProposalPlanWindow::for($proposal->status)->denialMessage());
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Milestone $milestone): bool
    {
        return $user->can('milestones.delete');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Milestone $milestone): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Milestone $milestone): bool
    {
        return false;
    }
}
