<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\StrategicPlanStatus;
use App\Models\Program;
use App\Models\StrategicPlan;
use App\Models\User;

/**
 * Who does what on a program's strategic plan (MEAL manager request,
 * 2026-11-13):
 *
 * - The MEAL manager writes it (User::buildsTheLogframe(): the MEAL Manager
 *   slot holder, or Super Admin / General Manager). Only a draft changes.
 * - The program's manager approves it or sends it back. While the program
 *   has no manager, the General Manager does. Super Admin always may.
 * - Everyone with strategies.view reads every plan.
 */
class StrategicPlanPolicy
{
    public function view(User $user, StrategicPlan $strategicPlan): bool
    {
        return $user->can('strategies.view');
    }

    /**
     * Write the plan of a program: a new one, or a draft.
     */
    public function write(User $user, ?StrategicPlan $strategicPlan = null): bool
    {
        if (! $user->can('strategies.view') || ! $user->buildsTheLogframe()) {
            return false;
        }

        return $strategicPlan === null || $strategicPlan->status === StrategicPlanStatus::Draft;
    }

    /**
     * Approve or send back a plan waiting for its program manager.
     */
    public function review(User $user, StrategicPlan $strategicPlan): bool
    {
        return $strategicPlan->status === StrategicPlanStatus::Submitted && $this->approvesFor($user, $strategicPlan->program_id);
    }

    /**
     * Take an approved plan back to a draft for a revision.
     */
    public function reopen(User $user, StrategicPlan $strategicPlan): bool
    {
        return $strategicPlan->status === StrategicPlanStatus::Approved && $user->buildsTheLogframe();
    }

    /**
     * The program's own manager; the General Manager while it has none.
     */
    public function approvesFor(User $user, int $programId): bool
    {
        if ($user->hasRole('Super Admin')) {
            return true;
        }

        $managerId = Program::query()->whereKey($programId)->value('manager_id');

        if ($managerId === null) {
            return $user->hasRole('General Manager');
        }

        return $user->employee_id !== null && (int) $user->employee_id === (int) $managerId;
    }
}
