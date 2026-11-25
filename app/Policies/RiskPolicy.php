<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Program;
use App\Models\Proposal;
use App\Models\Risk;
use App\Models\User;

/**
 * Risks follow their location row (Y-13, D-93): besides holders of the risks.*
 * permissions, the row's responsible employee and its program's manager (see
 * User::ownedLocationIds()) log and edit the risks of the rows they answer
 * for. Deleting stays with risks.delete.
 *
 * Program framework and proposal logframe risks are typed (or copied from
 * the program) by whoever may build the framework: `manageFramework`
 * (results.update, the MEAL manager); a PR officer only reads them. The project-based abilities above serve the
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
     * Determine whether the user can log a risk — on the given location row,
     * or (with no row yet chosen) on at least one row.
     */
    public function create(User $user, ?int $locationId = null): bool
    {
        if ($user->can('risks.create')) {
            return true;
        }

        $ownedLocationIds = $user->ownedLocationIds();

        return $locationId === null ? $ownedLocationIds !== [] : in_array($locationId, $ownedLocationIds, true);
    }

    /**
     * Determine whether the user can edit the risk.
     */
    public function update(User $user, Risk $risk): bool
    {
        return $user->can('risks.update') || in_array($risk->proposal_location_id, $user->ownedLocationIds(), true);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Risk $risk): bool
    {
        return $user->can('risks.delete');
    }

    /**
     * Determine whether the user can type, edit or remove the risks of a
     * program's results framework or a proposal's logframe: whoever builds
     * the framework (results.update).
     */
    public function manageFramework(User $user, Program|Proposal $owner): bool
    {
        return $user->can('results.update');
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
