<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\AnnualPlan;
use App\Models\Program;

/**
 * Deletes a program's annual plans through Eloquent before the program itself
 * goes.
 *
 * `annual_plans.program_id` cascades in the database, and a database cascade
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
        $program->annualPlans()->cursor()->each(
            fn (AnnualPlan $annualPlan) => $annualPlan->delete()
        );
    }
}
