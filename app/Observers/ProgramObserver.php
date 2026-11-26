<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\AnnualPlan;
use App\Models\Program;

/**
 * Keeps a program's dependents and the name lookup in step with it.
 */
class ProgramObserver
{
    /**
     * Drop the cached names and codes, so a rename is visible to the rest of
     * the request that made it (Program::lookup()).
     */
    public function saved(Program $program): void
    {
        Program::forgetLookup();
    }

    public function deleted(Program $program): void
    {
        Program::forgetLookup();
    }

    /**
     * Handle the deletion of a program.
     *
     * `annual_plans.program_id` cascades in the database, and a database
     * cascade fires no Eloquent events — so PlanIndicatorSourceObserver would
     * never see the plans disappear and their targets would be orphaned.
     * Deleting the plans here first means the cascade has nothing left to
     * remove.
     */
    public function deleting(Program $program): void
    {
        $program->annualPlans()->cursor()->each(
            fn (AnnualPlan $annualPlan) => $annualPlan->delete()
        );
    }
}
