<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Risk;
use App\Models\User;

/**
 * Risks follow their project: besides holders of the risks.* permissions, the
 * project's owner and its program's manager (see User::ownedProjectIds()) log
 * and edit the risks of projects they answer for. Deleting stays with
 * risks.delete.
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
