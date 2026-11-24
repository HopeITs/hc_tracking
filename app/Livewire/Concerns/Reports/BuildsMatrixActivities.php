<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Reports;

use App\Enums\ActivityKind;
use App\Enums\ActivityStatus;
use App\Enums\MonthlyReportStatus;
use App\Enums\ProposalStatus;
use App\Enums\ResultType;
use App\Models\HrCenter;
use App\Models\PlanIndicator;
use App\Support\MealCoverage;
use App\Support\Reporting\ExtractionFilter;
use App\Support\Reporting\PlaceBridge;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use stdClass;

/**
 * The Activities tab of the Monthly Matrix (T18 slice 2, D-51, contracts
 * §14.1): one row per logframe activity and center, grouped by output, with
 * a status per month.
 *
 * Used by BuildsMonthlyMatrix' page. A month shows the activity's own
 * validated entry; failing that the planned weeks of its calendar (a
 * project's override rows replace the proposal calendar, as in
 * MonthlyReportActivities); failing that the last validated status carried
 * forward. Pending (submitted) reports never show. Five reads, whatever the
 * number of activities.
 *
 * Still project-keyed (Y-05 Q2, until Y-17): the rows are (activity,
 * project), the pairs come from `project_proposal` through PlaceBridge, the
 * calendars read `activity_schedules.project_id` and the entries
 * `monthly_reports.project_id`, so a monthly report written after Y-03 (no
 * project) shows no activity status here yet.
 *
 * @property-read array{months: list<CarbonImmutable>, outputs: list<array<string, mixed>>, count: int} $activityBoard
 */
trait BuildsMatrixActivities
{
    /**
     * @return array{months: list<CarbonImmutable>, outputs: list<array<string, mixed>>, count: int}
     */
    #[Computed]
    public function activityBoard(): array
    {
        $months = $this->months;
        $empty = ['months' => $months, 'outputs' => [], 'count' => 0];

        if ($months === []) {
            return $empty;
        }

        $end = $months[count($months) - 1]->endOfMonth();
        $links = $this->activityLinks($months[0], $end);

        if ($links->isEmpty()) {
            return $empty;
        }

        $activities = DB::table('results as r')
            ->leftJoin('results as o', 'o.id', '=', 'r.parent_id')
            ->where('r.type', ResultType::Activity->value)
            ->whereIn('r.proposal_id', $links->pluck('proposal_id')->unique()->all())
            ->orderByRaw('o.id IS NULL')
            ->orderBy('o.id')
            ->orderBy('r.id')
            ->get(['r.id', 'r.statement', 'r.activity_kind', 'r.proposal_id', 'o.id as output_id', 'o.statement as output_statement']);

        if ($activities->isEmpty()) {
            return $empty;
        }

        $projectIds = $links->pluck('project_id')->unique()->all();
        $activityIds = $activities->pluck('id')->all();
        $calendars = $this->activityCalendars($activityIds, $projectIds);
        $entries = $this->activityEntries($activityIds, $projectIds, $end);

        $centers = [];
        foreach ($links->pluck('center_id')->filter()->unique() as $centerId) {
            $centers[(int) $centerId] = HrCenter::remembered((int) $centerId);
        }

        $linksByProposal = $links->groupBy('proposal_id');
        $outputs = [];
        $count = 0;

        foreach ($activities as $activity) {
            foreach ($linksByProposal->get($activity->proposal_id, collect()) as $link) {
                $project = (int) $link->project_id;
                // A free activity (no output above it) is grouped on its own, under key 0.
                $outputKey = (int) ($activity->output_id ?? 0);
                $outputs[$outputKey] ??= ['output_id' => $outputKey, 'statement' => $activity->output_statement ?? __('results_framework.free_activities'), 'activities' => []];
                $outputs[$outputKey]['activities'][] = [
                    'key' => 'activity-'.$activity->id.'-'.$project,
                    'activity_id' => (int) $activity->id,
                    'statement' => $activity->statement,
                    'kind' => ActivityKind::tryFrom((string) $activity->activity_kind) ?? ActivityKind::Continuous,
                    'project_id' => $project,
                    'center' => ($link->center_id === null ? null : ($centers[(int) $link->center_id]->name ?? null)) ?? $link->project_name,
                    'cells' => $this->activityCells($months, $calendars[$activity->id][$project] ?? [], $entries[$activity->id][$project] ?? []),
                ];
                $count++;
            }
        }

        return ['months' => $months, 'outputs' => array_values($outputs), 'count' => $count];
    }

    /**
     * The (proposal, project) pairs the page's filters select: donor-approved
     * proposals whose period overlaps the window.
     *
     * @return Collection<int, stdClass> rows of proposal_id, project_id, project_name, center_id
     */
    protected function activityLinks(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $this->activityLinksFor($this->extractionFilter, $from, $to);
    }

    /**
     * The projects whose place the signed-in MEAL officer covers (Y-14): the
     * projects of the targets at their covered places.
     *
     * @return list<int>
     */
    protected function coveredProjectIds(): array
    {
        $employeeId = Auth::user()?->employee_id;

        if ($employeeId === null) {
            return [];
        }

        return MealCoverage::scopeCoveredBy(PlanIndicator::query()->whereNotNull('project_id'), (int) $employeeId)
            ->distinct()
            ->pluck('project_id')
            ->map(intval(...))
            ->all();
    }

    /**
     * The (proposal, project) pairs a filter selects, through PlaceBridge:
     * the tab stays project-keyed until Y-17 (Y-05 Q2), and the place,
     * program and window filters are translated onto the projects there.
     *
     * @return Collection<int, stdClass> rows of proposal_id, project_id, project_name, center_id
     */
    protected function activityLinksFor(ExtractionFilter $filter, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return app(PlaceBridge::class)->activityLinks(
            $filter,
            $from,
            $to,
            array_map(fn (ProposalStatus $status): string => $status->value, ProposalStatus::approvedByDonor()),
            $filter->mine ? $this->coveredProjectIds() : null,
        );
    }

    /**
     * Each activity's planned months per project: the project's own override
     * rows when it has any, else the proposal calendar.
     *
     * @param  list<int>  $activityIds
     * @param  list<int>  $projectIds
     * @return array<int, array<int, array<string, list<int>|null>>> activity => project => Y-m => weeks (null = the whole month)
     */
    private function activityCalendars(array $activityIds, array $projectIds): array
    {
        $rows = DB::table('activity_schedules')
            ->whereIn('result_id', $activityIds)
            ->where(fn ($query) => $query->whereNull('project_id')->orWhereIn('project_id', $projectIds))
            ->get(['result_id', 'project_id', 'month', 'weeks']);

        $calendar = [];
        $overrides = [];
        foreach ($rows as $row) {
            $weeks = $row->weeks === null ? null : array_values(array_map('intval', (array) json_decode((string) $row->weeks, true)));
            $ym = substr((string) $row->month, 0, 7);

            if ($row->project_id === null) {
                $calendar[(int) $row->result_id][$ym] = $weeks;
            } else {
                $overrides[(int) $row->result_id][(int) $row->project_id][$ym] = $weeks;
            }
        }

        $result = [];
        foreach ($activityIds as $activityId) {
            foreach ($projectIds as $projectId) {
                $result[$activityId][$projectId] = $overrides[$activityId][$projectId] ?? $calendar[$activityId] ?? [];
            }
        }

        return $result;
    }

    /**
     * The validated entries up to the window's end, per activity and
     * project, oldest month first.
     *
     * @param  list<int>  $activityIds
     * @param  list<int>  $projectIds
     * @return array<int, array<int, array<string, ActivityStatus>>> activity => project => Y-m => status
     */
    private function activityEntries(array $activityIds, array $projectIds, CarbonImmutable $to): array
    {
        $rows = DB::table('monthly_report_activity_entries as e')
            ->join('monthly_reports as mr', 'mr.id', '=', 'e.monthly_report_id')
            ->where('mr.status', MonthlyReportStatus::Validated->value)
            ->where('mr.period', '<=', $to->toDateString())
            ->whereIn('mr.project_id', $projectIds)
            ->whereIn('e.result_id', $activityIds)
            ->whereNotNull('e.status')
            ->orderBy('mr.period')
            ->get(['e.result_id', 'mr.project_id', 'mr.period', 'e.status']);

        $entries = [];
        foreach ($rows as $row) {
            $entries[(int) $row->result_id][(int) $row->project_id][substr((string) $row->period, 0, 7)] = ActivityStatus::from((string) $row->status);
        }

        return $entries;
    }

    /**
     * The cells of one activity at one center, one per month of the window.
     *
     * @param  list<CarbonImmutable>  $months
     * @param  array<string, list<int>|null>  $planned
     * @param  array<string, ActivityStatus>  $entries
     * @return array<string, array{kind: string, status: ?ActivityStatus, weeks: ?list<int>}>
     */
    private function activityCells(array $months, array $planned, array $entries): array
    {
        $last = null;
        foreach ($entries as $ym => $status) {
            if ($ym < $months[0]->format('Y-m')) {
                $last = $status;
            }
        }

        $cells = [];
        foreach ($months as $month) {
            $ym = $month->format('Y-m');

            $cells[$ym] = match (true) {
                isset($entries[$ym]) => ['kind' => 'status', 'status' => $last = $entries[$ym], 'weeks' => null],
                array_key_exists($ym, $planned) => ['kind' => 'planned', 'status' => null, 'weeks' => $planned[$ym]],
                $last !== null => ['kind' => 'carried', 'status' => $last, 'weeks' => null],
                default => ['kind' => 'empty', 'status' => null, 'weeks' => null],
            };
        }

        return $cells;
    }
}
