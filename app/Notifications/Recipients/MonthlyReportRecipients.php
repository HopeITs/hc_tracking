<?php

declare(strict_types=1);

namespace App\Notifications\Recipients;

use App\Models\Activity;
use App\Models\MonthlyReport;
use App\Support\MealCoverage;

/**
 * Monthly reports (contracts §13 Events, §15.2, T17A). Oversight (MEAL +
 * General Management) comes from the event catalog.
 *
 * - `monthly_report.submitted` → the responsible MEAL officer and the MEAL
 *   Manager slot holder; MEAL role holders when both are missing.
 * - `.validated` / `.returned` → the preparer and the submitter (+ owner).
 * - `.reopen_requested` → the deciders the action named (`user_ids`).
 * - `.reopen_escalated` → the General Manager (no decider, D-09a).
 * - `.reopen_decided` → the requester (+ owner).
 * - anything else (`monthly_reports.generated` is listener driven): the
 *   project owner.
 */
final class MonthlyReportRecipients extends ProjectOwnedRecipients
{
    public function directEmployeeIds(Activity $activity): array
    {
        return match ($activity->event) {
            'monthly_report.submitted' => $this->mealEmployeeIds($activity),
            'monthly_report.reopen_requested', 'monthly_report.reopen_escalated' => [],
            default => parent::directEmployeeIds($activity),
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
    private function mealEmployeeIds(Activity $activity): array
    {
        $report = MonthlyReport::query()->with('project')->find($activity->subject_id);

        return $report === null ? [] : $this->uniqueIds([
            MealCoverage::responsibleFor($report->project),
            MealCoverage::mealManagerEmployeeId(),
        ]);
    }
}
