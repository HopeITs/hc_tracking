<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AnnualPlan;
use App\Models\User;

/**
 * Besides holders of the annual-plans.* permissions, a plan's own manager and
 * the manager of its program edit the plan. Moving it to another program or
 * manager stays with annual-plans.update holders.
 */
class AnnualPlanPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('annual-plans.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, AnnualPlan $annualPlan): bool
    {
        return $user->can('annual-plans.view');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('annual-plans.create');
    }

    /**
     * Determine whether the user can edit the plan.
     */
    public function update(User $user, AnnualPlan $annualPlan): bool
    {
        return $user->can('annual-plans.update')
            || ($user->employee_id !== null && $annualPlan->manager_id === $user->employee_id)
            || in_array($annualPlan->program_id, $user->managedProgramIds(), true);
    }

    /**
     * Determine whether the user can move the plan to another program or
     * manager: a management decision, not the plan manager's own.
     */
    public function reassign(User $user, AnnualPlan $annualPlan): bool
    {
        return $user->can('annual-plans.update');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, AnnualPlan $annualPlan): bool
    {
        return $user->can('annual-plans.delete');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, AnnualPlan $annualPlan): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, AnnualPlan $annualPlan): bool
    {
        return false;
    }
}
