<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Program;
use App\Models\YearlyPlan;

/**
 * Deletes a program's yearly plans through Eloquent before the program itself
 * goes.
 *
 * `yearly_plans.program_id` cascades in the database, and a database cascade
 * fires no Eloquent events — so PlanIndicatorSourceObserver would never see the
 * plans disappear and their targets would be orphaned. Deleting the plans here
 * first means the cascade has nothing left to remove.
 */
class ProgramObserver
{
    /**
     * Handle the deletion of a program.
     */
    public function deleting(Program $program): void
    {
        $program->yearlyPlans()->cursor()->each(
            fn (YearlyPlan $yearlyPlan) => $yearlyPlan->delete()
        );
    }
}
