<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ReportTemplate;
use App\Models\User;
use App\Support\MealCoverage;
use App\Support\Slots;

/**
 * A project's report template (contracts.md §12.4) is written by MEAL only:
 * holders of report-templates.manage who are the project's responsible MEAL
 * officer (MealCoverage::responsibleFor) or the MEAL Manager slot holder.
 * Its lines and questions follow the template. The project's owner and its
 * program's manager read it.
 */
class ReportTemplatePolicy
{
    /**
     * Determine whether the user can see the template: any MEAL holder, the
     * project's owner and its program's manager.
     */
    public function view(User $user, ReportTemplate $reportTemplate): bool
    {
        return $user->can('report-templates.manage')
            || in_array($reportTemplate->project_id, $user->ownedProjectIds(), true);
    }

    /**
     * Determine whether the user can edit, confirm or retire the template's
     * lines and questions.
     */
    public function manage(User $user, ReportTemplate $reportTemplate): bool
    {
        if (! $user->can('report-templates.manage') || $user->employee_id === null) {
            return false;
        }

        if ($user->employee_id === Slots::holderOf('meal_manager')) {
            return true;
        }

        $project = $reportTemplate->relationLoaded('project')
            ? $reportTemplate->project
            : $reportTemplate->project()->select(['id', 'city_id', 'center_id'])->first();

        return $project !== null && $user->employee_id === MealCoverage::responsibleFor($project);
    }

    /**
     * Templates are never deleted; the project's cascade removes them.
     */
    public function delete(User $user, ReportTemplate $reportTemplate): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, ReportTemplate $reportTemplate): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, ReportTemplate $reportTemplate): bool
    {
        return false;
    }
}
