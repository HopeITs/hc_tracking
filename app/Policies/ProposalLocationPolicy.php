<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ProposalLocation;
use App\Models\User;

/**
 * A location row is one entry on the Projects screen (Y-16, D-93), so the
 * abilities that gated a project gate the row, rule for rule:
 *
 * - besides holders of the `projects.*` permissions, the employee responsible
 *   for the row and the manager of its program answer for it
 *   (`User::ownedLocationIds()`) and may change what it delivers;
 * - nobody changes a row whose responsible has rejected the appointment;
 * - moving a row to another program or responsible stays a management
 *   decision (`projects.update`), never the responsible's;
 * - activating a row is MEAL confirming its report template (T33);
 * - rows are archived, never deleted.
 *
 * The permission keys stay `projects.*`: they name a screen and an ability,
 * not a table (`wave4/AGENT.md §8.5`).
 *
 * Writing a row **as part of its proposal** (the proposal form, the link
 * wrappers) is a different act and still authorizes on the locked proposal
 * itself, not here, as it did before Y-16.
 */
class ProposalLocationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('projects.view');
    }

    public function view(User $user, ProposalLocation $location): bool
    {
        return $user->can('projects.view');
    }

    /**
     * Nothing creates a row on its own: a row exists because a proposal has
     * it (D-84, D-87), so the Projects screen offers no "New project" and the
     * proposal form is the only way in (`proposals.update`).
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Change what the row delivers. Nobody can once its responsible has
     * rejected the appointment.
     */
    public function update(User $user, ProposalLocation $location): bool
    {
        if ($location->isOwnerRejected()) {
            return false;
        }

        return $user->can('projects.update') || in_array($location->id, $user->ownedLocationIds(), true);
    }

    /**
     * Move the row to another program or responsible: a management decision,
     * not the responsible's. Locked for everyone once they have rejected.
     */
    public function reassign(User $user, ProposalLocation $location): bool
    {
        return ! $location->isOwnerRejected() && $user->can('projects.update');
    }

    /**
     * Reopen a closed row as a draft (contracts.md §3). The proposal side
     * decides, so this follows `proposals.update`, never `projects.update`.
     */
    public function reopenAsDraft(User $user, ProposalLocation $location): bool
    {
        return ! $location->isOwnerRejected() && $user->can('proposals.update');
    }

    /**
     * Activate an approved row: MEAL, by confirming its report template (T33).
     */
    public function activate(User $user, ProposalLocation $location): bool
    {
        return $user->can('report-templates.manage');
    }

    /**
     * Archive or restore the row.
     */
    public function delete(User $user, ProposalLocation $location): bool
    {
        return $user->can('projects.delete');
    }

    public function restore(User $user, ProposalLocation $location): bool
    {
        return false;
    }

    public function forceDelete(User $user, ProposalLocation $location): bool
    {
        return false;
    }
}
