<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ActivityPlanStatus;
use App\Models\ActivityPlan;
use App\Models\Program;
use App\Models\User;
use App\Support\MealCoverage;

/**
 * Who does what on an annual activity plan (2026-11-12 decisions):
 *
 * - The program manager (the program's manager_id) makes the plan and sends
 *   it to MEAL; while a program has no manager, any annual-plans.update
 *   holder does. Only a draft can be changed.
 * - The MEAL manager reviews it: an annual-plans.review holder or the MEAL
 *   Manager slot holder. They add follow-up tasks, approve or send it back.
 * - Everyone with annual-plans.view reads every plan.
 */
class ActivityPlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('annual-plans.view');
    }

    public function view(User $user, ActivityPlan $activityPlan): bool
    {
        return $user->can('annual-plans.view');
    }

    /**
     * Start a plan, optionally for one program.
     */
    public function create(User $user, ?Program $program = null): bool
    {
        return $user->can('annual-plans.create') && ($program === null || $this->plansFor($user, $program));
    }

    /**
     * Change a draft plan.
     */
    public function update(User $user, ActivityPlan $activityPlan): bool
    {
        if ($activityPlan->status !== ActivityPlanStatus::Draft || ! $user->can('annual-plans.update')) {
            return false;
        }

        $program = $activityPlan->relationLoaded('program')
            ? $activityPlan->program
            : Program::query()->select(['id', 'manager_id'])->find($activityPlan->program_id);

        return $program !== null && $this->plansFor($user, $program);
    }

    /**
     * Review a plan that is with MEAL.
     */
    public function review(User $user, ActivityPlan $activityPlan): bool
    {
        return $activityPlan->status === ActivityPlanStatus::InReview && self::isMealManager($user);
    }

    public function delete(User $user, ActivityPlan $activityPlan): bool
    {
        return $activityPlan->status === ActivityPlanStatus::Draft && $user->can('annual-plans.delete') && $this->update($user, $activityPlan);
    }

    /**
     * The program's own manager, or anyone allowed while it has none.
     */
    public function plansFor(User $user, Program $program): bool
    {
        if ($user->hasRole('Super Admin')) {
            return true;
        }

        return $program->manager_id === null || ($user->employee_id !== null && (int) $user->employee_id === (int) $program->manager_id);
    }

    /**
     * An annual-plans.review holder or the MEAL Manager slot holder.
     */
    public static function isMealManager(User $user): bool
    {
        if ($user->can('annual-plans.review')) {
            return true;
        }

        $slotHolder = MealCoverage::mealManagerEmployeeId();

        return $slotHolder !== null && $user->employee_id !== null && (int) $user->employee_id === $slotHolder;
    }
}
