<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ActivityPlanStatus;
use App\Enums\PlanUpdateStatus;
use App\Models\ActivityPlan;
use App\Models\PlanUpdate;
use App\Models\Program;
use App\Models\ProposalLocation;
use App\Models\User;
use App\Support\MealCoverage;

/**
 * Who does what on a monthly plan update (2026-11-11 agreement):
 *
 * - The centre's project manager (the location row's responsible, Y-15)
 *   fills, saves and sends it, answers questions, and fixes it when returned.
 * - The row's MEAL officer (MealCoverage::responsibleForLocation, Y-14) writes
 *   Accountability & Feedback, approves, returns or asks.
 * - The MEAL manager (ActivityPlanPolicy::isMealManager) reviews, returns
 *   to the project manager or to the officer, or asks.
 * - The program manager marks it seen, with an optional note, only after
 *   the MEAL manager reviewed it.
 * - Everyone involved reads every part at every stage; the CEO (General
 *   Manager) sees the summary only.
 */
class PlanUpdatePolicy
{
    public function view(User $user, PlanUpdate $planUpdate): bool
    {
        return self::canRead($user, $planUpdate->plan()->firstOrFail(), $planUpdate->location()->firstOrFail());
    }

    /**
     * Only the chain of this centre's update reads it: its project manager,
     * its MEAL officer, the MEAL manager and the plan's program manager
     * (Super Admin passes each check). Another centre's manager does not.
     */
    public static function canRead(User $user, ActivityPlan $plan, ProposalLocation $location): bool
    {
        if (! $user->can('annual-plans.view') || ($user->hasRole('General Manager') && ! $user->hasRole('Super Admin'))) {
            return false;
        }

        return self::isProjectManager($user, $location)
            || self::isOfficer($user, $location)
            || ActivityPlanPolicy::isMealManager($user)
            || self::isProgramManager($user, $plan);
    }

    /**
     * Fill or fix the update of a centre (no update row yet is fine).
     */
    public static function canFill(User $user, ActivityPlan $plan, ProposalLocation $location, ?PlanUpdate $update): bool
    {
        return $plan->status === ActivityPlanStatus::Approved
            && self::isProjectManager($user, $location)
            && ($update === null || $update->status->isEditable());
    }

    public function decideAsOfficer(User $user, PlanUpdate $planUpdate): bool
    {
        return $planUpdate->status === PlanUpdateStatus::Sent && self::isOfficer($user, $planUpdate->location()->firstOrFail());
    }

    public function decideAsMealManager(User $user, PlanUpdate $planUpdate): bool
    {
        return $planUpdate->status === PlanUpdateStatus::OfficerApproved && ActivityPlanPolicy::isMealManager($user);
    }

    public function markSeen(User $user, PlanUpdate $planUpdate): bool
    {
        return $planUpdate->status === PlanUpdateStatus::Reviewed
            && self::isProgramManager($user, $planUpdate->plan()->firstOrFail());
    }

    public function answer(User $user, PlanUpdate $planUpdate): bool
    {
        return in_array($planUpdate->status, [PlanUpdateStatus::Sent, PlanUpdateStatus::OfficerApproved, PlanUpdateStatus::Reviewed], true)
            && self::isProjectManager($user, $planUpdate->location()->firstOrFail());
    }

    public function downloadFile(User $user, PlanUpdate $planUpdate): bool
    {
        return $this->view($user, $planUpdate);
    }

    /**
     * The centre's project manager: the location row's responsible employee.
     */
    public static function isProjectManager(User $user, ProposalLocation $location): bool
    {
        if ($user->hasRole('Super Admin')) {
            return true;
        }

        $owner = $location->responsible_employee_id;

        return $user->employee_id !== null && $owner !== null && (int) $owner === (int) $user->employee_id;
    }

    /**
     * The centre's MEAL officer(s): who covers the row's place (Y-14). With
     * none set, any MEAL role holder.
     */
    public static function isOfficer(User $user, ProposalLocation $location): bool
    {
        if ($user->hasRole('Super Admin')) {
            return true;
        }

        $officers = MealCoverage::responsibleForLocation($location);

        return $officers === []
            ? $user->hasRole('MEAL')
            : $user->employee_id !== null && in_array((int) $user->employee_id, $officers, true);
    }

    public static function isProgramManager(User $user, ActivityPlan $plan): bool
    {
        if ($user->hasRole('Super Admin')) {
            return true;
        }

        $managerId = Program::query()->whereKey($plan->program_id)->value('manager_id');

        return $managerId !== null && $user->employee_id !== null && (int) $managerId === (int) $user->employee_id;
    }
}
