<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Reports;

use App\Enums\ActivityKind;
use App\Enums\ActivityStatus;
use App\Enums\MonthlyReportStatus;
use App\Enums\ProposalStatus;
use App\Enums\ResultType;
use App\Models\ActivitySchedule;
use App\Models\PlanIndicator;
use App\Models\ProposalLocation;
use App\Support\MealCoverage;
use App\Support\Reporting\ExtractionFilter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;

/**
 * The Activities tab of the Monthly Matrix (T18 slice 2, D-51, contracts
 * §14.1): one row per logframe activity and center, grouped by output, with
 * a status per month.
 *
 * Used by BuildsMonthlyMatrix' page. A month shows the activity's own
 * validated entry; failing that the planned weeks of its calendar (a row's
 * override rows replace the proposal calendar); failing that the last
 * validated status carried forward. Pending (submitted) reports never show.
 * Five reads, whatever the number of activities.
 *
 * Row-keyed since Y-16 (D-93), which closes Y-05 Q2 and Y-15 deviation 2: the
 * rows are (activity, **location row**), the pairs are the rows of the
 * donor-approved proposals themselves, the calendars go through
 * `ActivitySchedule::effectiveOf()` rather than a sixth copy of the override
 * rule, and an entry reaches its row through its report's template place
 * (Y-02) instead of `monthly_reports.project_id`.
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

        $locationIds = $links->pluck('id')->map(intval(...))->unique()->all();
        $activityIds = $activities->pluck('id')->all();
        $calendars = $this->activityCalendars($activityIds, $locationIds);
        $entries = $this->activityEntries($activityIds, $links, $end);

        $linksByProposal = $links->groupBy('proposal_id');
        $outputs = [];
        $count = 0;

        foreach ($activities as $activity) {
            foreach ($linksByProposal->get($activity->proposal_id, collect()) as $row) {
                $locationId = (int) $row->id;
                // A free activity (no output above it) is grouped on its own, under key 0.
                $outputKey = (int) ($activity->output_id ?? 0);
                $outputs[$outputKey] ??= ['output_id' => $outputKey, 'statement' => $activity->output_statement ?? __('results_framework.free_activities'), 'activities' => []];
                $outputs[$outputKey]['activities'][] = [
                    'key' => 'activity-'.$activity->id.'-'.$locationId,
                    'activity_id' => (int) $activity->id,
                    'statement' => $activity->statement,
                    'kind' => ActivityKind::tryFrom((string) $activity->activity_kind) ?? ActivityKind::Continuous,
                    'location_id' => $locationId,
                    'center' => $row->label(),
                    'cells' => $this->activityCells($months, $calendars[$activity->id][$locationId] ?? [], $entries[$activity->id][$locationId] ?? []),
                ];
                $count++;
            }
        }

        return ['months' => $months, 'outputs' => array_values($outputs), 'count' => $count];
    }

    /**
     * The location rows the page's filters select: the rows of donor-approved
     * proposals whose period overlaps the window.
     *
     * @return Collection<int, ProposalLocation>
     */
    protected function activityLinks(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $this->activityLinksFor($this->extractionFilter, $from, $to);
    }

    /**
     * The location rows whose place the signed-in MEAL officer covers (Y-14):
     * the rows of the targets at their covered places.
     *
     * @return list<int>
     */
    protected function coveredLocationIds(): array
    {
        $employeeId = Auth::user()?->employee_id;

        if ($employeeId === null) {
            return [];
        }

        return MealCoverage::scopeCoveredBy(PlanIndicator::query()->whereNotNull('proposal_location_id'), (int) $employeeId)
            ->distinct()
            ->pluck('proposal_location_id')
            ->map(intval(...))
            ->all();
    }

    /**
     * The rows a filter selects (Y-16, D-93). A row carries its own proposal,
     * city, program and centres, so no pivot and no `projects` read is needed:
     * the place filters of D-79 read the row itself.
     *
     * @return Collection<int, ProposalLocation>
     */
    protected function activityLinksFor(ExtractionFilter $filter, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $statuses = array_map(fn (ProposalStatus $status): string => $status->value, ProposalStatus::approvedByDonor());
        $covered = $filter->mine ? $this->coveredLocationIds() : null;

        $begin = 'case when pp.actual_date_begin is not null and pp.actual_date_end is not null then pp.actual_date_begin else pp.planned_date_begin end';
        $end = 'case when pp.actual_date_begin is not null and pp.actual_date_end is not null then coalesce(pp.extended_date_end, pp.actual_date_end) else pp.planned_date_end end';

        /** @var Collection<int, ProposalLocation> $rows */
        $rows = ProposalLocation::query()
            ->join('proposals as pp', 'pp.id', '=', 'proposal_locations.proposal_id')
            ->whereIn('pp.status', $statuses)
            ->whereRaw("({$begin}) <= ?", [$to->toDateString()])
            ->whereRaw("({$end}) >= ?", [$from->toDateString()])
            ->when($filter->proposalId !== null, fn ($query) => $query->where('pp.id', $filter->proposalId))
            ->when($filter->donorId !== null, fn ($query) => $query->where('pp.donor_id', $filter->donorId))
            ->when($filter->cityId !== null, fn ($query) => $query->where('proposal_locations.city_id', $filter->cityId))
            ->when($filter->programId !== null, fn ($query) => $query->where('proposal_locations.program_id', $filter->programId))
            ->when($filter->centerId !== null, fn ($query) => $query->whereHas('centers', fn ($centers) => $centers->where('center_id', $filter->centerId)))
            ->when($filter->cityWide, fn ($query) => $query->where('proposal_locations.city_wide', true))
            ->when($filter->centerUnset, fn ($query) => $query->where('proposal_locations.city_wide', false)->whereDoesntHave('centers'))
            ->when($filter->pillarId !== null, fn ($query) => $query->whereIn('proposal_locations.program_id', DB::table('programs')->where('strategic_pillar_id', $filter->pillarId)->select('id')))
            ->when($covered !== null, fn ($query) => $covered === [] ? $query->whereRaw('1 = 0') : $query->whereIn('proposal_locations.id', $covered))
            ->with(['city', 'program', 'centers'])
            ->orderBy('proposal_locations.sort')
            ->orderBy('proposal_locations.id')
            ->select('proposal_locations.*')
            ->get();

        return $rows;
    }

    /**
     * Each activity's planned months per location row: the row's own override
     * rows when it has any, else the proposal calendar.
     *
     * One read for every activity, then `ActivitySchedule::effectiveOf()` —
     * the one definition of the override rule (Y-15) — per pair, so this is
     * not a sixth inline copy of it.
     *
     * @param  list<int>  $activityIds
     * @param  list<int>  $locationIds
     * @return array<int, array<int, array<string, list<int>|null>>> activity => row => Y-m => weeks (null = the whole month)
     */
    private function activityCalendars(array $activityIds, array $locationIds): array
    {
        $schedules = ActivitySchedule::query()
            ->select(['id', 'result_id', 'proposal_location_id', 'month', 'weeks'])
            ->whereIn('result_id', $activityIds)
            ->where(fn ($query) => $query->whereNull('proposal_location_id')->orWhereIn('proposal_location_id', $locationIds))
            ->get()
            ->groupBy('result_id');

        $result = [];

        foreach ($activityIds as $activityId) {
            $rows = $schedules->get($activityId, collect());

            foreach ($locationIds as $locationId) {
                $result[$activityId][$locationId] = ActivitySchedule::effectiveOf($rows, $locationId);
            }
        }

        return $result;
    }

    /**
     * The validated entries up to the window's end, per activity and location
     * row, oldest month first.
     *
     * An entry belongs to a monthly report, and a report belongs to a template
     * whose place is the row's place (Y-02, Y-03). So a row collects the
     * entries of every template at its place — which is how two proposals
     * sharing a place share a report (D-77) — instead of matching
     * `monthly_reports.project_id`.
     *
     * @param  list<int>  $activityIds
     * @param  Collection<int, ProposalLocation>  $links
     * @return array<int, array<int, array<string, ActivityStatus>>> activity => row => Y-m => status
     */
    private function activityEntries(array $activityIds, Collection $links, CarbonImmutable $to): array
    {
        $rows = DB::table('monthly_report_activity_entries as e')
            ->join('monthly_reports as mr', 'mr.id', '=', 'e.monthly_report_id')
            ->join('report_templates as rt', 'rt.id', '=', 'mr.report_template_id')
            ->where('mr.status', MonthlyReportStatus::Validated->value)
            ->where('mr.period', '<=', $to->toDateString())
            ->whereIn('e.result_id', $activityIds)
            ->whereNotNull('e.status')
            ->orderBy('mr.period')
            ->get(['e.result_id', 'mr.period', 'e.status', 'rt.city_id', 'rt.program_id', 'rt.center_id', 'rt.city_wide']);

        // Which rows answer for each template place: same city and program,
        // and either the row is city-wide and so is the template, or the
        // template's centre is one of the row's.
        $entries = [];

        foreach ($rows as $entry) {
            foreach ($links as $link) {
                if ((int) $link->city_id !== (int) $entry->city_id || (int) $link->program_id !== (int) $entry->program_id) {
                    continue;
                }

                $matches = match (true) {
                    // Field work on both sides (D-88).
                    (bool) $entry->city_wide => $link->city_wide,
                    // The template's centre is one of the row's (D-90).
                    $entry->center_id !== null => in_array((int) $entry->center_id, $link->centerIds(), true),
                    // Neither has a centre yet: "centre not set" (D-82).
                    default => ! $link->city_wide && $link->centerIds() === [],
                };

                if (! $matches) {
                    continue;
                }

                $entries[(int) $entry->result_id][(int) $link->id][substr((string) $entry->period, 0, 7)] = ActivityStatus::from((string) $entry->status);
            }
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
