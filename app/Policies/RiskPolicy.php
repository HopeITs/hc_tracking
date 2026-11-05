<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Proposal;
use App\Models\Risk;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Risks follow their project: besides holders of the risks.* permissions, the
 * project's owner and its program's manager (see User::ownedProjectIds()) log
 * and edit the risks of projects they answer for. Deleting stays with
 * risks.delete.
 *
 * Since W-09 (D-61) new risks are picked from the library onto a proposal's
 * logframe: `attach` and `detach` follow whoever may update that proposal
 * (ProposalPolicy::update). The project-based abilities above serve the
 * legacy rows only.
 */
class RiskPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('risks.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Risk $risk): bool
    {
        return $user->can('risks.view');
    }

    /**
     * Determine whether the user can log a risk — on the given project, or
     * (with no project yet chosen) on at least one project.
     */
    public function create(User $user, ?int $projectId = null): bool
    {
        if ($user->can('risks.create')) {
            return true;
        }

        $ownedProjectIds = $user->ownedProjectIds();

        return $projectId === null ? $ownedProjectIds !== [] : in_array($projectId, $ownedProjectIds, true);
    }

    /**
     * Determine whether the user can edit the risk.
     */
    public function update(User $user, Risk $risk): bool
    {
        return $user->can('risks.update') || in_array($risk->project_id, $user->ownedProjectIds(), true);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Risk $risk): bool
    {
        return $user->can('risks.delete');
    }

    /**
     * Determine whether the user can pick a library risk onto the proposal's logframe.
     */
    public function attach(User $user, Proposal $proposal): bool
    {
        return Gate::forUser($user)->allows('update', $proposal);
    }

    /**
     * Determine whether the user can remove a risk from the proposal's logframe.
     */
    public function detach(User $user, Proposal $proposal): bool
    {
        return Gate::forUser($user)->allows('update', $proposal);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Risk $risk): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Risk $risk): bool
    {
        return false;
    }
}
