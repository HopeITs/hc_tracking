<?php

declare(strict_types=1);

namespace App\Notifications\Recipients;

use App\Models\Activity;
use App\Models\MonthlyReport;
use App\Support\MealCoverage;
use App\Support\MonthlyReportPreparers;
use App\Support\Reporting\PlaceBridge;

/**
 * Monthly reports (contracts §13 Events, §15.2, T17A, Y-03). Oversight (MEAL +
 * General Management) comes from the event catalog. The "owner" of §15.2 is
 * the report's preparer side (D-84): the responsibles of the template's
 * location rows at its place (MonthlyReportPreparers), never a project owner.
 *
 * - `monthly_report.submitted` → the responsible MEAL officer (reached
 *   through PlaceBridge until coverage moves to places) and the MEAL Manager
 *   slot holder; MEAL role holders when both are missing.
 * - `.validated` / `.returned` → the preparer and the submitter (+ the
 *   template's responsibles).
 * - `.reopen_requested` → the deciders the action named (`user_ids`).
 * - `.reopen_escalated` → the General Manager (no decider, D-09a).
 * - `.reopen_decided` → the requester (+ the template's responsibles).
 * - anything else (`monthly_reports.generated` is listener driven): the
 *   template's responsibles.
 */
final class MonthlyReportRecipients extends ModuleRecipients
{
    public function directEmployeeIds(Activity $activity): array
    {
        return match ($activity->event) {
            'monthly_report.submitted' => $this->mealEmployeeIds($activity),
            'monthly_report.reopen_requested', 'monthly_report.reopen_escalated' => [],
            default => $this->uniqueIds([
                ...$this->responsibleEmployeeIds($activity),
                ...$this->idsFromProperty($activity, 'employee_ids'),
            ]),
        };
    }

    public function directUserIds(Activity $activity): array
    {
        return match ($activity->event) {
            'monthly_report.validated', 'monthly_report.returned' => $this->uniqueIds(array_values(
                MonthlyReport::query()->whereKey($activity->subject_id)->first(['id', 'prepared_by', 'submitted_by'])?->only(['prepared_by', 'submitted_by']) ?? []
            )),
            'monthly_report.reopen_requested' => $this->idsFromProperty($activity, 'user_ids'),
            'monthly_report.reopen_decided' => $this->idsFromProperty($activity, 'requester_id'),
            default => [],
        };
    }

    public function watcherRoles(Activity $activity): array
    {
        return match ($activity->event) {
            'monthly_report.submitted' => $this->mealEmployeeIds($activity) === [] ? ['MEAL'] : [],
            'monthly_report.reopen_escalated' => ['General Manager'],
            default => [],
        };
    }

    /**
     * @return list<int>
     */
    private function responsibleEmployeeIds(Activity $activity): array
    {
        $template = MonthlyReport::query()->with('template')->find($activity->subject_id)?->template;

        return $template === null ? [] : app(MonthlyReportPreparers::class)->responsibleEmployeeIds($template);
    }

    /**
     * @return list<int>
     */
    private function mealEmployeeIds(Activity $activity): array
    {
        $template = MonthlyReport::query()->with('template')->find($activity->subject_id)?->template;

        if ($template === null) {
            return [];
        }

        $project = app(PlaceBridge::class)->legacyProjectFor($template);

        return $this->uniqueIds([
            $project === null ? null : MealCoverage::responsibleFor($project),
            MealCoverage::mealManagerEmployeeId(),
        ]);
    }
}
