<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Activity;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportActivityEntry;
use App\Models\ProjectActivityStatus;
use App\Models\ProposalLocation;
use App\Models\Result;
use Illuminate\Validation\ValidationException;

/**
 * `project_activity_statuses` written on Validate and restored on a granted
 * reopen (contracts §13, §13.1 item 8, D-51). Both run inside the calling
 * action's transaction, after the report row is locked.
 *
 * Y-15 (D-93): a status belongs to a location row. Each entry's activity is
 * written on the row of **its own proposal** at the report template's place
 * (ReportTemplate::locationIdsByProposal()), with that row's `project_id`
 * (still written until Y-18).
 */
final class MonthlyReportActivityStatuses
{
    /**
     * Write the statuses confirmed by the report: one row per entry with a
     * status. A start date the entry does not give keeps the previous one.
     * An activity whose proposal has no row at the template's place is
     * refused on `activities` and nothing is written (the whole validation
     * rolls back).
     *
     * @return list<array{result_id: int, previous: array{status: string, started_on: ?string, completed_on: ?string, monthly_report_id: int}|null}> the previous state of each touched row, stored in the `monthly_report.validated` activity properties
     *
     * @throws ValidationException
     */
    public function confirm(MonthlyReport $report): array
    {
        $entries = $report->activityEntries()->whereNotNull('status')->orderBy('result_id')->get();

        if ($entries->isEmpty()) {
            return [];
        }

        $rowOf = $this->rowsOf($report, array_values($entries->pluck('result_id')->map(fn (mixed $id): int => (int) $id)->all()));

        $current = ProjectActivityStatus::query()
            ->whereIn('proposal_location_id', array_values(array_unique(array_map(fn (ProposalLocation $row): int => $row->id, $rowOf))))
            ->whereIn('result_id', array_keys($rowOf))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (ProjectActivityStatus $status): string => $status->proposal_location_id.':'.$status->result_id);

        return array_values($entries->map(function (MonthlyReportActivityEntry $entry) use ($current, $report, $rowOf): array {
            $location = $rowOf[$entry->result_id];
            $row = $current->get($location->id.':'.$entry->result_id);
            $previous = $row === null ? null : [
                'status' => $row->status->value,
                'started_on' => $row->started_on?->toDateString(),
                'completed_on' => $row->completed_on?->toDateString(),
                'monthly_report_id' => $row->monthly_report_id,
            ];

            ($row ?? new ProjectActivityStatus(['proposal_location_id' => $location->id, 'result_id' => $entry->result_id]))
                ->fill([
                    'project_id' => $location->project_id,
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
     * later report has confirmed since is left alone. The rows are found by
     * the report that wrote them (`monthly_report_id`, `result_id`): one per
     * activity, since a report writes each activity once.
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

    /**
     * The location row each activity is confirmed on: the row of its own
     * proposal at the report template's place.
     *
     * @param  list<int>  $activityIds
     * @return array<int, ProposalLocation> activity id => row
     *
     * @throws ValidationException
     */
    private function rowsOf(MonthlyReport $report, array $activityIds): array
    {
        $proposalOf = Result::query()->whereKey($activityIds)->pluck('proposal_id', 'id');
        $template = $report->loadMissing('template')->template;
        $proposalIds = array_values(array_unique(array_map(intval(...), array_filter($proposalOf->all()))));
        $locationIds = $template === null ? [] : $template->locationIdsByProposal($proposalIds);
        $rows = ProposalLocation::query()->whereKey(array_values($locationIds))->get(['id', 'proposal_id', 'project_id'])->keyBy('id');

        $rowOf = [];

        foreach ($activityIds as $activityId) {
            $proposalId = $proposalOf->get($activityId);
            $row = $proposalId === null ? null : $rows->get($locationIds[(int) $proposalId] ?? 0);

            if ($row === null) {
                throw ValidationException::withMessages([
                    'activities' => __('monthly_reports.activity_no_location', [
                        'proposal' => (string) Result::query()->whereKey($activityId)->with('proposal:id,name')->first()?->proposal?->name,
                    ]),
                ]);
            }

            $rowOf[$activityId] = $row;
        }

        return $rowOf;
    }
}
