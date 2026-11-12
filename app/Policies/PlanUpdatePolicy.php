<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ActivityPlanStatus;
use App\Enums\PlanUpdateStatus;
use App\Models\ActivityPlan;
use App\Models\PlanUpdate;
use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use App\Support\MealCoverage;

/**
 * Who does what on a monthly plan update (2026-11-11 agreement):
 *
 * - The centre's project manager (the project owner) fills, saves and sends
 *   it, answers questions, and fixes it when returned.
 * - The governorate MEAL officer (MealCoverage::responsibleFor) writes
 *   Accountability & Feedback, approves, returns or asks.
 * - The MEAL manager (ActivityPlanPolicy::isMealManager) reviews, returns
 *   to the project manager or to the officer, or asks.
 * - The program manager marks it seen, with an optional note.
 * - Everyone involved reads every part at every stage; the CEO (General
 *   Manager) sees the summary only.
 */
class PlanUpdatePolicy
{
    public function view(User $user, PlanUpdate $planUpdate): bool
    {
        return self::canRead($user);
    }

    public static function canRead(User $user): bool
    {
        return $user->can('annual-plans.view') && ! ($user->hasRole('General Manager') && ! $user->hasRole('Super Admin'));
    }

    /**
     * Fill or fix the update of a centre (no update row yet is fine).
     */
    public static function canFill(User $user, ActivityPlan $plan, Project $project, ?PlanUpdate $update): bool
    {
        return $plan->status === ActivityPlanStatus::Approved
            && self::isProjectManager($user, $project)
            && ($update === null || $update->status->isEditable());
    }

    public function decideAsOfficer(User $user, PlanUpdate $planUpdate): bool
    {
        return $planUpdate->status === PlanUpdateStatus::Sent && self::isOfficer($user, $planUpdate->project()->firstOrFail());
    }

    public function decideAsMealManager(User $user, PlanUpdate $planUpdate): bool
    {
        return $planUpdate->status === PlanUpdateStatus::OfficerApproved && ActivityPlanPolicy::isMealManager($user);
    }

    public function markSeen(User $user, PlanUpdate $planUpdate): bool
    {
        return $planUpdate->status === PlanUpdateStatus::Reviewed && self::isProgramManager($user, $planUpdate->plan()->firstOrFail());
    }

    public function answer(User $user, PlanUpdate $planUpdate): bool
    {
        return in_array($planUpdate->status, [PlanUpdateStatus::Sent, PlanUpdateStatus::OfficerApproved, PlanUpdateStatus::Reviewed], true)
            && self::isProjectManager($user, $planUpdate->project()->firstOrFail());
    }

    public function downloadFile(User $user, PlanUpdate $planUpdate): bool
    {
        return self::canRead($user);
    }

    public static function isProjectManager(User $user, Project $project): bool
    {
        if ($user->hasRole('Super Admin')) {
            return true;
        }

        $owner = $project->getAttribute('user_id') ?? Project::ownerEmployeeIdFor($project->id);

        return $user->employee_id !== null && $owner !== null && (int) $owner === (int) $user->employee_id;
    }

    public static function isOfficer(User $user, Project $project): bool
    {
        if ($user->hasRole('Super Admin')) {
            return true;
        }

        $responsible = MealCoverage::responsibleFor($project);

        return $responsible === null
            ? $user->hasRole('MEAL')
            : $user->employee_id !== null && (int) $user->employee_id === $responsible;
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
