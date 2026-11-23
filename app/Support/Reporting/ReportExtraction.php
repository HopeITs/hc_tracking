<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use App\Enums\DonorAttribution;
use App\Enums\IndicatorMeasure;
use App\Enums\MonthlyReportStatus;
use App\Enums\ProposalKind;
use App\Enums\ReportStatus;
use App\Models\PlanIndicator;
use App\Models\Report;
use App\Support\MealCoverage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The one query behind the Monthly Matrix, its export and the monthly-reports
 * list (Y-05, D-79, contracts §14, §14.1): every reader asks it for its rows
 * and none builds its own `where`, so a figure never differs between screen
 * and file.
 *
 * Place filters read the report template (the line's place) or, for targets,
 * the target's own place columns; a shared target (D-90) takes the place of
 * every template that holds it. The proposal filter reads the line shares'
 * targets, never `project_proposal`. Payroll proposals (D-55b) are left out.
 * No money column is selected.
 *
 * Figures: only validated monthly reports (approved mirrors) are summed;
 * counts sum the values in the window (the latest one when cumulative);
 * ratios pool only through PlanIndicator::pooledRatioSql(). A flat number of
 * queries whatever the number of places, months and indicators.
 *
 * @phpstan-type CenterRow array{key: string, place: ReportPlace, library_id: int, code: string, measure: IndicatorMeasure, is_cumulative: bool, attribution: DonorAttribution, target: float, target_ids: list<int>, shared_target_ids: list<int>, pooled_ids: list<int>, total: float|null, excluded: int, achieved: float|null}
 */
final class ReportExtraction
{
    /** @var list<CarbonImmutable>|null */
    private ?array $months = null;

    /** @var Collection<int, stdClass>|null */
    private ?Collection $targetRows = null;

    /** @var array<string, CenterRow>|null */
    private ?array $centerTotals = null;

    private function __construct(private readonly ExtractionFilter $filter) {}

    public static function for(ExtractionFilter $filter): self
    {
        return new self($filter);
    }

    public function filter(): ExtractionFilter
    {
        return $this->filter;
    }

    /**
     * The key of a place, as ReportPlace::key(): "city:program:cN", with
     * "city-wide" or "unset" in place of the center.
     */
    public static function placeKey(int $cityId, int $programId, ?int $centerId, bool $cityWide): string
    {
        return (new ReportPlace($cityId, $programId, $centerId, $cityWide))->key();
    }

    /**
     * The months of the window, inclusive, as first days. An open `to` is this
     * month; an open `from` is the earliest month of a monthly report the
     * other filters select (January of `to`'s year when there is none). Empty
     * when `from` is after `to`.
     *
     * @return list<CarbonImmutable>
     */
    public function months(): array
    {
        if ($this->months !== null) {
            return $this->months;
        }

        $to = $this->filter->to ?? CarbonImmutable::today()->startOfMonth();
        $from = $this->filter->from ?? $this->firstReportedMonth($to);
        $months = [];

        for ($month = $from; $month->lessThanOrEqualTo($to); $month = $month->addMonth()) {
            $months[] = $month;
        }

        return $this->months = $months;
    }

    /**
     * The targets the filters select whose source covers the window, one row
     * per target and place: a shared target (D-90) gives one row per place of
     * a template holding it (none when no template holds it). Columns:
     * plan_id, target, source_type, source_id, program_id, is_shared,
     * place_city_id, place_program_id, place_center_id, place_city_wide,
     * library_id, code, name_ar, name_en, unit, is_cumulative,
     * is_disaggregated, denominator_basis, is_active, measure,
     * donor_attribution, proposal_id, proposal_name, proposal_slug, donor_id,
     * donor_name, donor_slug, plan_year, proposal_start, proposal_end,
     * shared_centers (a shared target: how many places hold it, whatever
     * the filters; else null).
     */
    public function targets(): Builder
    {
        $proposalStart = 'case when pp.actual_date_begin is not null and pp.actual_date_end is not null then pp.actual_date_begin else pp.planned_date_begin end';
        $proposalEnd = 'case when pp.actual_date_begin is not null and pp.actual_date_end is not null then coalesce(pp.extended_date_end, pp.actual_date_end) else pp.planned_date_end end';
        $window = $this->window();

        $sharedPlaces = DB::table('report_template_lines as rtl')
            ->join('report_templates as rt', 'rt.id', '=', 'rtl.report_template_id')
            ->whereNotNull('rt.city_id')
            ->whereNotNull('rt.program_id')
            ->distinct()
            ->select(['rtl.plan_indicator_id', 'rt.city_id', 'rt.program_id', 'rt.center_id', 'rt.city_wide']);

        $inner = DB::table('plan_indicators as p')
            ->join('indicators as ind', 'ind.id', '=', 'p.indicator_id')
            ->join('library_indicators as li', 'li.id', '=', 'ind.library_indicator_id')
            ->leftJoin('programs as pg', 'pg.id', '=', 'p.program_id')
            ->leftJoin('proposals as pp', fn ($join) => $join->on('pp.id', '=', 'p.source_id')->where('p.source_type', '=', 'proposal'))
            ->leftJoin('donors as d', 'd.id', '=', 'pp.donor_id')
            ->leftJoin('annual_plans as ap', fn ($join) => $join->on('ap.id', '=', 'p.source_id')->where('p.source_type', '=', 'annual_plan'))
            ->leftJoinSub($sharedPlaces, 'sp', fn ($join) => $join->on('sp.plan_indicator_id', '=', 'p.id')->where('p.all_centers', '=', true))
            ->where(fn (Builder $query) => $query->where('p.all_centers', false)->orWhereNotNull('sp.plan_indicator_id'))
            ->where(fn (Builder $query) => $query->whereNull('pp.id')->orWhere('pp.kind', '!=', ProposalKind::Payroll->value))
            ->when($this->filter->proposalId !== null, fn (Builder $query) => $query->where('pp.id', $this->filter->proposalId))
            ->when($this->filter->donorId !== null, fn (Builder $query) => $query->where('pp.donor_id', $this->filter->donorId))
            ->when($this->filter->indicatorId !== null, fn (Builder $query) => $query->where('li.id', $this->filter->indicatorId))
            ->select([
                'p.id as plan_id', 'p.target', 'p.source_type', 'p.source_id', 'p.program_id', 'p.all_centers as is_shared',
                'li.id as library_id', 'li.code', 'li.name_ar', 'li.name_en', 'li.unit', 'li.is_cumulative', 'li.is_disaggregated', 'li.denominator_basis', 'li.is_active',
                'ind.measure', 'pg.donor_attribution',
                'pp.id as proposal_id', 'pp.name as proposal_name', 'pp.slug as proposal_slug', 'pp.donor_id',
                'd.name as donor_name', 'd.slug as donor_slug', 'ap.year as plan_year',
            ])
            ->selectRaw("($proposalStart) as proposal_start, ($proposalEnd) as proposal_end")
            ->selectRaw('case when p.all_centers = 1 then sp.city_id else p.city_id end as place_city_id')
            ->selectRaw('case when p.all_centers = 1 then sp.program_id else p.program_id end as place_program_id')
            ->selectRaw('case when p.all_centers = 1 then sp.center_id else p.center_id end as place_center_id')
            ->selectRaw('case when p.all_centers = 1 then sp.city_wide else p.city_wide end as place_city_wide')
            ->selectRaw('case when p.all_centers = 1 then (select count(distinct coalesce(rt3.center_id, 0), rt3.city_wide) from report_template_lines rtl3 join report_templates rt3 on rt3.id = rtl3.report_template_id where rtl3.plan_indicator_id = p.id and rt3.city_id is not null and rt3.program_id is not null) end as shared_centers');

        if ($window === null) {
            $inner->whereRaw('1 = 0');
        } else {
            [$from, $to] = $window;
            $inner->where(function (Builder $covers) use ($from, $to, $proposalStart, $proposalEnd): void {
                $covers->where(function (Builder $proposalTargets) use ($from, $to, $proposalStart, $proposalEnd): void {
                    $proposalTargets->whereNotNull('pp.id')
                        ->whereRaw("($proposalStart) <= ?", [$to->toDateString()])
                        ->whereRaw("($proposalEnd) >= ?", [$from->toDateString()]);
                })->orWhere(function (Builder $planTargets) use ($from, $to): void {
                    $planTargets->whereNotNull('ap.id')->whereBetween('ap.year', [$from->year, $to->year]);
                });
            });
        }

        $query = DB::query()->fromSub($inner, 't');
        $this->wherePlace($query, 't.place_city_id', 't.place_program_id', 't.place_center_id', 't.place_city_wide');

        return $query
            ->orderBy('t.place_city_id')
            ->orderBy('t.place_program_id')
            ->orderBy('t.place_center_id')
            ->orderBy('t.code')
            ->orderBy('t.plan_id')
            ->select('t.*');
    }

    /**
     * The monthly report lines of the window, of every status, at the places
     * the filters select. Columns: id, monthly_report_id, report_template_id,
     * place_city_id, place_program_id, place_center_id, place_city_wide,
     * status, period, library_indicator_id, value, numerator, denominator,
     * fixed_denominator, not_measured.
     */
    public function lines(): Builder
    {
        $query = $this->linesBase()
            ->when($this->filter->proposalId !== null, fn (Builder $q) => $q->whereExists(fn (Builder $exists) => $this->sharesOfLine($exists)
                ->where('p2.source_type', 'proposal')
                ->where('p2.source_id', $this->filter->proposalId)))
            ->when($this->filter->donorId !== null, fn (Builder $q) => $q->whereExists(fn (Builder $exists) => $this->sharesOfLine($exists)
                ->join('proposals as pp2', 'pp2.id', '=', 'p2.source_id')
                ->where('p2.source_type', 'proposal')
                ->where('pp2.donor_id', $this->filter->donorId)));

        return $query->select([
            'l.id', 'l.monthly_report_id', 'rt.id as report_template_id',
            'rt.city_id as place_city_id', 'rt.program_id as place_program_id', 'rt.center_id as place_center_id', 'rt.city_wide as place_city_wide',
            'mr.status', 'mr.period',
            'l.library_indicator_id', 'l.value', 'l.numerator', 'l.denominator', 'l.fixed_denominator', 'l.not_measured',
        ]);
    }

    /**
     * The line shares of the window, of every status, with their `reports`
     * mirror (the donor view's figures). Columns: share_id,
     * plan_indicator_id, monthly_report_line_id, monthly_report_id,
     * report_template_id, place_city_id, place_program_id, place_center_id,
     * place_city_wide, status, period, library_indicator_id, value,
     * numerator, denominator, fixed_denominator, not_measured, report_id,
     * report_status, report_value, report_numerator, report_denominator.
     */
    public function shares(): Builder
    {
        return $this->linesBase()
            ->join('monthly_report_line_shares as s', 's.monthly_report_line_id', '=', 'l.id')
            ->join('plan_indicators as p', 'p.id', '=', 's.plan_indicator_id')
            ->leftJoin('proposals as pp', fn ($join) => $join->on('pp.id', '=', 'p.source_id')->where('p.source_type', '=', 'proposal'))
            ->leftJoin('reports as r', 'r.monthly_report_line_share_id', '=', 's.id')
            ->where(fn (Builder $query) => $query->whereNull('pp.id')->orWhere('pp.kind', '!=', ProposalKind::Payroll->value))
            ->when($this->filter->proposalId !== null, fn (Builder $q) => $q->where('p.source_type', 'proposal')->where('p.source_id', $this->filter->proposalId))
            ->when($this->filter->donorId !== null, fn (Builder $q) => $q->where('pp.donor_id', $this->filter->donorId))
            ->select([
                's.id as share_id', 's.plan_indicator_id', 'l.id as monthly_report_line_id', 'l.monthly_report_id', 'rt.id as report_template_id',
                'rt.city_id as place_city_id', 'rt.program_id as place_program_id', 'rt.center_id as place_center_id', 'rt.city_wide as place_city_wide',
                'mr.status', 'mr.period', 'l.library_indicator_id',
                's.value', 's.numerator', 's.denominator', 's.fixed_denominator', 's.not_measured',
                'r.id as report_id', 'r.status as report_status', 'r.value as report_value', 'r.numerator as report_numerator', 'r.denominator as report_denominator',
            ]);
    }

    /**
     * The monthly reports of the window at the places the filters select; the
     * proposal and donor filters read the template's proposals, the indicator
     * filter its lines. Columns: every `monthly_reports` column, plus
     * place_city_id, place_program_id, place_center_id, place_city_wide.
     *
     * With `$openEndUnbounded` (the monthly-reports list, Y-05 slice 2), an
     * open `to` has no upper bound, so generated months still to come are
     * listed; an open `from` has no lower bound either way.
     */
    public function reports(bool $openEndUnbounded = false): Builder
    {
        $unbounded = $openEndUnbounded && $this->filter->to === null;

        return $this->reportsBase(! $unbounded)
            ->when($unbounded && $this->filter->from !== null, fn (Builder $query) => $query->where('mr.period', '>=', $this->filter->from?->toDateString()))
            ->select([
                'mr.*', 'rt.city_id as place_city_id', 'rt.program_id as place_program_id', 'rt.center_id as place_center_id', 'rt.city_wide as place_city_wide',
            ]);
    }

    /**
     * The center view's figures, one row per (place, library entry)
     * (contracts §14.1), keyed "placeKey|libraryId". Two templates at one
     * place are one row. The target: `split` → Σ the row's targets, `shared`
     * → the highest; a shared target (D-90) is the whole target on each of its
     * centers' rows (`shared_target_ids`). `total`: counts sum the validated
     * lines (the latest per template when cumulative, summed over the
     * templates); ratios pool the row's targets through pooledRatioSql().
     * `achieved` is total ÷ target in percent.
     *
     * @return array<string, CenterRow>
     */
    public function centerTotals(): array
    {
        if ($this->centerTotals !== null) {
            return $this->centerTotals;
        }

        $rows = [];

        foreach ($this->targetRows()->filter(fn (stdClass $row): bool => $row->place_city_id !== null && $row->place_program_id !== null)->groupBy(fn (stdClass $row): string => $this->rowKey($row, (int) $row->library_id)) as $key => $group) {
            $first = $group->first();
            $attribution = $first->donor_attribution === null ? DonorAttribution::Shared : DonorAttribution::from($first->donor_attribution);
            $ids = self::idList($group->pluck('plan_id'));
            $highest = $group->sort(fn (stdClass $a, stdClass $b): int => [(float) $b->target, (int) $a->plan_id] <=> [(float) $a->target, (int) $b->plan_id])->first();

            $rows[$key] = [
                'key' => (string) $key,
                'place' => $this->placeOf($first),
                'library_id' => (int) $first->library_id,
                'code' => (string) $first->code,
                'measure' => IndicatorMeasure::from($first->measure),
                'is_cumulative' => (bool) $first->is_cumulative,
                'attribution' => $attribution,
                'target' => $attribution->combine(array_values($group->map(fn (stdClass $row): float => (float) $row->target)->all())),
                'target_ids' => $ids,
                'shared_target_ids' => self::idList($group->filter(fn (stdClass $row): bool => (bool) $row->is_shared)->pluck('plan_id')),
                'pooled_ids' => $attribution === DonorAttribution::Split ? $ids : [(int) $highest->plan_id],
                'total' => null,
                'excluded' => 0,
                'achieved' => null,
            ];
        }

        if ($rows === []) {
            return $this->centerTotals = [];
        }

        $counts = $this->validatedLineCounts($rows);
        $pooled = $this->pooledByRow(array_filter($rows, fn (array $row): bool => $row['measure'] !== IndicatorMeasure::Numeric));

        foreach ($rows as $key => $row) {
            $total = $row['measure'] === IndicatorMeasure::Numeric
                ? ($counts[$key] ?? 0.0)
                : ($pooled[$key]['ratio'] ?? null);

            $rows[$key]['total'] = $total;
            $rows[$key]['excluded'] = $pooled[$key]['excluded'] ?? 0;
            $rows[$key]['achieved'] = $this->achieved($total, $row['target']);
        }

        return $this->centerTotals = $rows;
    }

    /**
     * The center view's totals per library entry: the figures of every row of
     * the entry together, a shared target (D-90) counted once. Counts: the
     * target is Σ each row's own targets (combined by attribution) plus each
     * shared target once, the total Σ the rows' totals. Ratios: the targets
     * pooled together through pooledRatioSql(); the target only when every
     * target agrees.
     *
     * @return array<int, array{library_id: int, measure: IndicatorMeasure, target: float|null, total: float|null, excluded: int, places_count: int, achieved: float|null}>
     */
    public function libraryTotals(): array
    {
        $rows = collect($this->centerTotals());

        if ($rows->isEmpty()) {
            return [];
        }

        $targetValues = $this->targetRows()->mapWithKeys(fn (stdClass $row): array => [(int) $row->plan_id => (float) $row->target])->all();
        $ratioRows = $rows->filter(fn (array $row): bool => $row['measure'] !== IndicatorMeasure::Numeric);
        $sharedLibraries = $ratioRows->filter(fn (array $row): bool => array_intersect($row['pooled_ids'], $row['shared_target_ids']) !== [])->pluck('library_id')->unique()->all();
        $pooled = $this->pooledBy(self::idList($ratioRows->whereNotIn('library_id', $sharedLibraries)->pluck('pooled_ids')->flatten()), 'library_id');
        $pooled += $this->pooledPerTemplate($ratioRows->whereIn('library_id', $sharedLibraries)->groupBy('library_id')
            ->map(fn (Collection $group): array => ['ids' => self::idList($group->pluck('pooled_ids')->flatten()), 'place' => null])
            ->all());
        $result = [];

        foreach ($rows->groupBy('library_id') as $libraryId => $group) {
            $first = $group->first();
            $isNumeric = $first['measure'] === IndicatorMeasure::Numeric;
            $sharedIds = $group->pluck('shared_target_ids')->flatten()->unique()->values()->all();

            if ($isNumeric) {
                $target = $group->sum(fn (array $row): float => $row['attribution']->combine(array_values(array_map(
                    fn (int $id): float => $targetValues[$id],
                    array_diff($row['target_ids'], $row['shared_target_ids']),
                ))));
                $target += array_sum(array_map(fn (int $id): float => $targetValues[$id], $sharedIds));
                $total = (float) $group->sum('total');
            } else {
                $values = array_values(array_unique(array_map(fn (int $id): float => $targetValues[$id], $group->pluck('target_ids')->flatten()->unique()->all())));
                $target = count($values) === 1 ? $values[0] : null;
                $total = $pooled[(int) $libraryId]['ratio'] ?? null;
            }

            $result[(int) $libraryId] = [
                'library_id' => (int) $libraryId,
                'measure' => $first['measure'],
                'target' => $target,
                'total' => $total,
                'excluded' => $isNumeric ? 0 : ($pooled[(int) $libraryId]['excluded'] ?? 0),
                'places_count' => $group->count(),
                'achieved' => $this->achieved($total, $target),
            ];
        }

        return $result;
    }

    /**
     * The donor view's figures, one row per target (contracts §14.1), keyed
     * by target id: the approved share mirrors at the places the filters
     * select, summed (the latest per template when cumulative, summed over
     * the templates); ratios pool the target through pooledRatioSql(), a
     * shared target (D-90) one term per template through pooledRatio().
     *
     * @return array<int, array{plan_id: int, library_id: int, measure: IndicatorMeasure, is_shared: bool, target: float, total: float|null, excluded: int, achieved: float|null}>
     */
    public function targetTotals(): array
    {
        $targets = $this->targetRows()->unique('plan_id')->keyBy(fn (stdClass $row): int => (int) $row->plan_id);

        if ($targets->isEmpty()) {
            return [];
        }

        $mirrors = $this->shares()
            ->where('r.status', ReportStatus::Approved->value)
            ->whereNotNull('r.monthly_report_id')
            ->whereIn('s.plan_indicator_id', $targets->keys()->all())
            ->get();
        $ratioTargets = $targets->filter(fn (stdClass $row): bool => $row->measure !== IndicatorMeasure::Numeric->value);
        $pooled = $this->pooledBy(self::idList($ratioTargets->reject(fn (stdClass $row): bool => (bool) $row->is_shared)->keys()), 'id');
        $pooled += $this->pooledPerTemplate($ratioTargets->filter(fn (stdClass $row): bool => (bool) $row->is_shared)
            ->mapWithKeys(fn (stdClass $row, int $planId): array => [$planId => ['ids' => [$planId], 'place' => null]])
            ->all());
        $result = [];

        foreach ($targets as $planId => $row) {
            $measure = IndicatorMeasure::from($row->measure);
            $total = $measure === IndicatorMeasure::Numeric
                ? $this->countOf($mirrors->where('plan_indicator_id', $planId), (bool) $row->is_cumulative, 'report_value')
                : ($pooled[$planId]['ratio'] ?? null);

            $result[$planId] = [
                'plan_id' => $planId,
                'library_id' => (int) $row->library_id,
                'measure' => $measure,
                'is_shared' => (bool) $row->is_shared,
                'target' => (float) $row->target,
                'total' => $total,
                'excluded' => $pooled[$planId]['excluded'] ?? 0,
                'achieved' => $this->achieved($total, (float) $row->target),
            ];
        }

        return $result;
    }

    /**
     * The donor view's totals per library entry (the grouped mode): counts
     * add the targets and their totals; ratios pool every target together
     * (a shared one per template) and have a target only when every target
     * agrees.
     *
     * @return array<int, array{library_id: int, measure: IndicatorMeasure, target: float|null, total: float|null, excluded: int, achieved: float|null}>
     */
    public function targetLibraryTotals(): array
    {
        $totals = collect($this->targetTotals());

        if ($totals->isEmpty()) {
            return [];
        }

        $ratioTotals = $totals->filter(fn (array $row): bool => $row['measure'] !== IndicatorMeasure::Numeric);
        $sharedLibraries = $ratioTotals->where('is_shared', true)->pluck('library_id')->unique()->all();
        $pooled = $this->pooledBy(self::idList($ratioTotals->whereNotIn('library_id', $sharedLibraries)->pluck('plan_id')), 'library_id');
        $pooled += $this->pooledPerTemplate($ratioTotals->whereIn('library_id', $sharedLibraries)->groupBy('library_id')
            ->map(fn (Collection $group): array => ['ids' => self::idList($group->pluck('plan_id')), 'place' => null])
            ->all());
        $result = [];

        foreach ($totals->groupBy('library_id') as $libraryId => $group) {
            $measure = $group->first()['measure'];
            $isNumeric = $measure === IndicatorMeasure::Numeric;
            $values = $group->pluck('target')->unique()->values()->all();
            $target = $isNumeric ? (float) $group->sum('target') : (count($values) === 1 ? (float) $values[0] : null);
            $total = $isNumeric ? (float) $group->sum('total') : ($pooled[(int) $libraryId]['ratio'] ?? null);

            $result[(int) $libraryId] = [
                'library_id' => (int) $libraryId,
                'measure' => $measure,
                'target' => $target,
                'total' => $total,
                'excluded' => $isNumeric ? 0 : ($pooled[(int) $libraryId]['excluded'] ?? 0),
                'achieved' => $this->achieved($total, $target),
            ];
        }

        return $result;
    }

    /**
     * The first and last day of the window; null when it holds no month.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private function window(): ?array
    {
        $months = $this->months();

        return $months === [] ? null : [$months[0], $months[count($months) - 1]->endOfMonth()->startOfDay()];
    }

    /**
     * The earliest reported month the other filters select, or January of
     * `to`'s year.
     */
    private function firstReportedMonth(CarbonImmutable $to): CarbonImmutable
    {
        $earliest = $this->reportsBase(false)->min('mr.period');
        $month = $earliest === null ? null : CarbonImmutable::parse((string) $earliest)->startOfMonth();

        return $month === null || $month->greaterThan($to) ? $to->startOfYear() : $month;
    }

    /**
     * Lines ⨝ monthly reports ⨝ templates in the window, with the place and
     * indicator filters.
     */
    private function linesBase(): Builder
    {
        $query = DB::table('monthly_report_lines as l')
            ->join('monthly_reports as mr', 'mr.id', '=', 'l.monthly_report_id')
            ->join('report_templates as rt', 'rt.id', '=', 'mr.report_template_id')
            ->when($this->filter->indicatorId !== null, fn (Builder $q) => $q->where('l.library_indicator_id', $this->filter->indicatorId));

        $this->whereWindow($query);
        $this->wherePlace($query, 'rt.city_id', 'rt.program_id', 'rt.center_id', 'rt.city_wide');

        return $query;
    }

    /**
     * The shares of the outer line `l`, with their target as `p2`.
     */
    private function sharesOfLine(Builder $exists): Builder
    {
        return $exists->selectRaw('1')
            ->from('monthly_report_line_shares as s2')
            ->join('plan_indicators as p2', 'p2.id', '=', 's2.plan_indicator_id')
            ->whereColumn('s2.monthly_report_line_id', 'l.id');
    }

    /**
     * Monthly reports ⨝ templates, optionally in the window, with every
     * filter.
     */
    private function reportsBase(bool $windowed): Builder
    {
        $query = DB::table('monthly_reports as mr')
            ->join('report_templates as rt', 'rt.id', '=', 'mr.report_template_id')
            ->when($this->filter->proposalId !== null, fn (Builder $q) => $q->whereExists(fn (Builder $exists) => $exists->selectRaw('1')
                ->from('proposal_report_template as prt')
                ->whereColumn('prt.report_template_id', 'rt.id')
                ->where('prt.proposal_id', $this->filter->proposalId)))
            ->when($this->filter->donorId !== null, fn (Builder $q) => $q->whereExists(fn (Builder $exists) => $exists->selectRaw('1')
                ->from('proposal_report_template as prt')
                ->join('proposals as pp2', 'pp2.id', '=', 'prt.proposal_id')
                ->whereColumn('prt.report_template_id', 'rt.id')
                ->where('pp2.donor_id', $this->filter->donorId)))
            ->when($this->filter->indicatorId !== null, fn (Builder $q) => $q->whereExists(fn (Builder $exists) => $exists->selectRaw('1')
                ->from('monthly_report_lines as l2')
                ->whereColumn('l2.monthly_report_id', 'mr.id')
                ->where('l2.library_indicator_id', $this->filter->indicatorId)));

        if ($windowed) {
            $this->whereWindow($query);
        }

        $this->wherePlace($query, 'rt.city_id', 'rt.program_id', 'rt.center_id', 'rt.city_wide');

        return $query;
    }

    private function whereWindow(Builder $query): void
    {
        $window = $this->window();

        if ($window === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereBetween('mr.period', [$window[0]->toDateString(), $window[1]->toDateString()]);
    }

    /**
     * The city, program, center, Field work, Center not set, pillar and "my
     * places" filters on the given place columns.
     */
    private function wherePlace(Builder $query, string $city, string $program, string $center, string $cityWide): void
    {
        $filter = $this->filter;

        $query
            ->when($filter->cityId !== null, fn (Builder $q) => $q->where($city, $filter->cityId))
            ->when($filter->programId !== null, fn (Builder $q) => $q->where($program, $filter->programId))
            ->when($filter->centerId !== null, fn (Builder $q) => $q->where($center, $filter->centerId)->where($cityWide, false))
            ->when($filter->cityWide, fn (Builder $q) => $q->where($cityWide, true))
            ->when($filter->centerUnset, fn (Builder $q) => $q->whereNull($center)->where($cityWide, false))
            ->when($filter->pillarId !== null, fn (Builder $q) => $q->whereIn($program, DB::table('programs')->where('strategic_pillar_id', $filter->pillarId)->select('id')));

        if (! $filter->mine) {
            return;
        }

        $employeeId = Auth::user()?->employee_id;
        if ($employeeId === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $columns = ['city_id' => $city, 'program_id' => $program, 'center_id' => $center, 'city_wide' => $cityWide];
        MealCoverage::constrainPlaceColumns($query, (int) $employeeId, fn (string $name): string => $columns[$name]);
    }

    private function wherePlaceIs(Builder $query, ReportPlace $place, string $city, string $program, string $center, string $cityWide): Builder
    {
        return $query->where($city, $place->cityId)
            ->where($program, $place->programId)
            ->where($cityWide, $place->cityWide)
            ->when($place->centerId === null, fn (Builder $q) => $q->whereNull($center), fn (Builder $q) => $q->where($center, $place->centerId));
    }

    /**
     * The rows of targets(), read once.
     *
     * @return Collection<int, stdClass>
     */
    public function targetRows(): Collection
    {
        return $this->targetRows ??= $this->targets()->get();
    }

    private function rowKey(stdClass $row, int $libraryId): string
    {
        return self::placeKey((int) $row->place_city_id, (int) $row->place_program_id, $row->place_center_id === null ? null : (int) $row->place_center_id, (bool) $row->place_city_wide).'|'.$libraryId;
    }

    private function placeOf(stdClass $row): ReportPlace
    {
        return new ReportPlace((int) $row->place_city_id, (int) $row->place_program_id, $row->place_center_id === null ? null : (int) $row->place_center_id, (bool) $row->place_city_wide);
    }

    /**
     * Each count row's total from the validated lines of the window, in one
     * query.
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @return array<string, float>
     */
    private function validatedLineCounts(array $rows): array
    {
        $lines = $this->lines()
            ->where('mr.status', MonthlyReportStatus::Validated->value)
            ->whereIn('l.library_indicator_id', array_values(array_unique(array_column($rows, 'library_id'))))
            ->get()
            ->groupBy(fn (stdClass $line): string => $this->rowKey($line, (int) $line->library_indicator_id));

        $counts = [];

        foreach ($rows as $key => $row) {
            if ($row['measure'] === IndicatorMeasure::Numeric) {
                $counts[$key] = $this->countOf($lines->get($key, collect()), $row['is_cumulative'], 'value');
            }
        }

        return $counts;
    }

    /**
     * Σ the figures, or, when cumulative, Σ over the templates of each one's
     * latest figure in the window.
     *
     * @param  Collection<int, stdClass>  $figures  with report_template_id, period and the value column
     */
    private function countOf(Collection $figures, bool $isCumulative, string $column): float
    {
        $figures = $figures->filter(fn (stdClass $figure): bool => $figure->{$column} !== null);

        if (! $isCumulative) {
            return (float) $figures->sum(fn (stdClass $figure): float => (float) $figure->{$column});
        }

        return (float) $figures->groupBy('report_template_id')
            ->sum(fn (Collection $template): float => (float) $template->sortBy('period')->last()->{$column});
    }

    /**
     * The pooled ratio of each ratio row over its pooled targets, in one
     * aggregate through pooledRatioSql(). A shared target held at several
     * places pools its whole `reports` history on each of their rows: the
     * mirrors carry no place.
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @return array<string, array{ratio: float|null, excluded: int}>
     */
    private function pooledByRow(array $rows): array
    {
        $window = $this->window();

        if ($rows === [] || $window === null) {
            return [];
        }

        $sharedRows = array_filter($rows, fn (array $row): bool => array_intersect($row['pooled_ids'], $row['shared_target_ids']) !== []);
        $perTemplate = $this->pooledPerTemplate(array_map(fn (array $row): array => ['ids' => $row['pooled_ids'], 'place' => $row['place']], $sharedRows));
        $rows = array_diff_key($rows, $sharedRows);

        if ($rows === []) {
            return $perTemplate;
        }

        $grammar = DB::connection()->getQueryGrammar();
        $columns = ['t.place_city_id', 't.place_program_id', 't.place_center_id', 't.place_city_wide'];

        $terms = DB::table('plan_indicators')
            ->joinSub($this->targets(), 't', 't.plan_id', '=', 'plan_indicators.id')
            ->where(function (Builder $any) use ($rows, $columns): void {
                foreach ($rows as $row) {
                    $any->orWhere(fn (Builder $one) => $this->wherePlaceIs($one->whereIn('t.plan_id', $row['pooled_ids'])->where('t.library_id', $row['library_id']), $row['place'], ...$columns));
                }
            })
            ->select(['plan_indicators.id', ...$columns, 't.library_id'])
            ->selectRaw(PlanIndicator::pooledTermsSelectSql($window[0], $window[1])->getValue($grammar));

        $groups = DB::query()
            ->fromSub($terms, 'pooled')
            ->groupBy('pooled.place_city_id', 'pooled.place_program_id', 'pooled.place_center_id', 'pooled.place_city_wide', 'pooled.library_id')
            ->select(['pooled.place_city_id', 'pooled.place_program_id', 'pooled.place_center_id', 'pooled.place_city_wide', 'pooled.library_id'])
            ->selectRaw(PlanIndicator::pooledRatioSql()->getValue($grammar).' as ratio, '.PlanIndicator::pooledExcludedCountSql()->getValue($grammar).' as excluded')
            ->get();

        $result = [];

        foreach ($groups as $group) {
            $result[$this->rowKey($group, (int) $group->library_id)] = [
                'ratio' => $group->ratio === null ? null : round((float) $group->ratio, 2),
                'excluded' => (int) $group->excluded,
            ];
        }

        return $result + $perTemplate;
    }

    /**
     * The pooled ratio of each group of targets through
     * PlanIndicator::pooledRatio(), where a shared target (D-90) is one term
     * per template that holds it, as MonthlyReportSharedTargets reads it:
     * each center keeps the per-target rule and the centers pool together.
     * With a place, only that place's templates count (a center's own
     * figure); without one, every template the filters select. Any other
     * target is one term over its approved rows in the window. Five queries
     * at most, whatever the number of groups.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, array{ids: list<int>, place: ReportPlace|null}>  $groups
     * @return array<TKey, array{ratio: float|null, excluded: int}>
     */
    private function pooledPerTemplate(array $groups): array
    {
        $window = $this->window();

        if ($groups === [] || $window === null) {
            return [];
        }

        [$from, $to] = $window;
        $ids = array_values(array_unique(array_merge(...array_column($groups, 'ids'))));
        $sharedIds = self::idList($this->targetRows()->filter(fn (stdClass $row): bool => (bool) $row->is_shared)->pluck('plan_id'));

        $targets = PlanIndicator::query()
            ->whereKey($ids)
            ->with(['indicator:id,measure,library_indicator_id', 'indicator.libraryIndicator:id,denominator_basis,is_cumulative'])
            ->with(['reports' => fn ($query) => $query->where('status', ReportStatus::Approved->value)->whereBetween('planned_date', [$from->toDateString(), $to->toDateString()])])
            ->get(['id', 'indicator_id', 'target', 'all_centers'])
            ->keyBy('id');

        $reportIds = $targets->only($sharedIds)->flatMap(fn (PlanIndicator $target) => $target->reports->pluck('monthly_report_id'))->filter()->unique()->values()->all();
        $templateOfReport = [];

        if ($reportIds !== []) {
            $query = DB::table('monthly_reports as mr')
                ->join('report_templates as rt', 'rt.id', '=', 'mr.report_template_id')
                ->whereIn('mr.id', $reportIds);
            $this->wherePlace($query, 'rt.city_id', 'rt.program_id', 'rt.center_id', 'rt.city_wide');

            foreach ($query->get(['mr.id', 'rt.id as template_id', 'rt.city_id', 'rt.program_id', 'rt.center_id', 'rt.city_wide']) as $row) {
                $templateOfReport[(int) $row->id] = [
                    'template' => (int) $row->template_id,
                    'place' => self::placeKey((int) $row->city_id, (int) $row->program_id, $row->center_id === null ? null : (int) $row->center_id, (bool) $row->city_wide),
                ];
            }
        }

        $result = [];

        foreach ($groups as $key => $group) {
            $terms = [];
            $placeKey = $group['place']?->key();

            foreach (array_unique($group['ids']) as $id) {
                $target = $targets->get($id);

                if ($target === null) {
                    continue;
                }

                if (! in_array($id, $sharedIds, true)) {
                    $terms[] = $target;

                    continue;
                }

                $byTemplate = $target->reports
                    ->filter(fn (Report $mirror): bool => isset($templateOfReport[(int) $mirror->monthly_report_id])
                        && ($placeKey === null || $templateOfReport[(int) $mirror->monthly_report_id]['place'] === $placeKey))
                    ->groupBy(fn (Report $mirror): int => $templateOfReport[(int) $mirror->monthly_report_id]['template']);

                foreach ($byTemplate as $mirrors) {
                    $term = clone $target;
                    $terms[] = $term->setRelation('reports', new EloquentCollection($mirrors->values()->all()));
                }
            }

            $pooled = PlanIndicator::pooledRatio($terms, $from, $to);
            $result[$key] = [
                'ratio' => $pooled['ratio'] === null ? null : round($pooled['ratio'], 2),
                'excluded' => $pooled['excluded'],
            ];
        }

        return $result;
    }

    /**
     * The pooled ratio of the given targets over the window, per target
     * (`id`) or per library entry (`library_id`), in one aggregate through
     * pooledRatioSql(). Each target counts once, so a shared target's fixed
     * denominator is counted once (Y-02 handoff).
     *
     * @param  list<int>  $targetIds
     * @param  'id'|'library_id'  $by
     * @return array<int, array{ratio: float|null, excluded: int}>
     */
    private function pooledBy(array $targetIds, string $by): array
    {
        $window = $this->window();

        if ($targetIds === [] || $window === null) {
            return [];
        }

        $grammar = DB::connection()->getQueryGrammar();

        $terms = DB::table('plan_indicators')
            ->whereIn('plan_indicators.id', $targetIds)
            ->select(['plan_indicators.id'])
            ->selectRaw('(select i2.library_indicator_id from indicators i2 where i2.id = plan_indicators.indicator_id) as library_id, '.PlanIndicator::pooledTermsSelectSql($window[0], $window[1])->getValue($grammar));

        $groups = DB::query()
            ->fromSub($terms, 'pooled')
            ->groupBy('pooled.'.$by)
            ->select(['pooled.'.$by.' as group_id'])
            ->selectRaw(PlanIndicator::pooledRatioSql()->getValue($grammar).' as ratio, '.PlanIndicator::pooledExcludedCountSql()->getValue($grammar).' as excluded')
            ->get();

        $result = [];

        foreach ($groups as $group) {
            $result[(int) $group->group_id] = [
                'ratio' => $group->ratio === null ? null : round((float) $group->ratio, 2),
                'excluded' => (int) $group->excluded,
            ];
        }

        return $result;
    }

    /**
     * Ids as a list of distinct integers.
     *
     * @param  Collection<array-key, mixed>  $ids
     * @return list<int>
     */
    private static function idList(Collection $ids): array
    {
        return array_values(array_unique(array_map(intval(...), $ids->all())));
    }

    private function achieved(?float $total, ?float $target): ?float
    {
        return $total === null || $target === null || $target <= 0.0 ? null : round($total / $target * 100, 2);
    }
}
