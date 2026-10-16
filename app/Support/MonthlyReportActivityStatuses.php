<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Activity;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportActivityEntry;
use App\Models\ProjectActivityStatus;

/**
 * `project_activity_statuses` written on Validate and restored on a granted
 * reopen (contracts §13, §13.1 item 8, D-51). Both run inside the calling
 * action's transaction, after the report row is locked.
 */
final class MonthlyReportActivityStatuses
{
    /**
     * Write the statuses confirmed by the report: one row per entry with a
     * status. A start date the entry does not give keeps the previous one.
     *
     * @return list<array{result_id: int, previous: array{status: string, started_on: ?string, completed_on: ?string, monthly_report_id: int}|null}> the previous state of each touched row, stored in the `monthly_report.validated` activity properties
     */
    public function confirm(MonthlyReport $report): array
    {
        $entries = $report->activityEntries()->whereNotNull('status')->orderBy('result_id')->get();

        if ($entries->isEmpty()) {
            return [];
        }

        $current = ProjectActivityStatus::query()
            ->where('project_id', $report->project_id)
            ->whereIn('result_id', $entries->pluck('result_id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('result_id');

        return array_values($entries->map(function (MonthlyReportActivityEntry $entry) use ($current, $report): array {
            $row = $current->get($entry->result_id);
            $previous = $row === null ? null : [
                'status' => $row->status->value,
                'started_on' => $row->started_on?->toDateString(),
                'completed_on' => $row->completed_on?->toDateString(),
                'monthly_report_id' => $row->monthly_report_id,
            ];

            ($row ?? new ProjectActivityStatus(['project_id' => $report->project_id, 'result_id' => $entry->result_id]))
                ->fill([
                    'status' => $entry->status,
                    'started_on' => $entry->started_on ?? $row?->started_on,
                    'completed_on' => $entry->completed_on ?? $entry->executed_on,
                    'monthly_report_id' => $report->id,
                ])
                ->save();

            return ['result_id' => $entry->result_id, 'previous' => $previous];
        })->all());
    }

    /**
     * Restore the statuses this report's latest validation replaced. A row a
     * later report has confirmed since is left alone.
     */
    public function restore(MonthlyReport $report): void
    {
        $validation = Activity::query()
            ->where('subject_type', $report->getMorphClass())
            ->where('subject_id', $report->id)
            ->where('event', 'monthly_report.validated')
            ->latest('id')
            ->first();

        /** @var list<array{result_id: int, previous: array{status: string, started_on: ?string, completed_on: ?string, monthly_report_id: int}|null}> $touched */
        $touched = $validation?->properties['activity_statuses'] ?? [];

        if ($touched === []) {
            return;
        }

        $current = ProjectActivityStatus::query()
            ->where('project_id', $report->project_id)
            ->where('monthly_report_id', $report->id)
            ->whereIn('result_id', array_column($touched, 'result_id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('result_id');

        foreach ($touched as $item) {
            $row = $current->get($item['result_id']);

            if ($row === null) {
                continue;
            }

            if ($item['previous'] === null) {
                $row->delete();

                continue;
            }

            $row->fill($item['previous'])->save();
        }
    }
}
