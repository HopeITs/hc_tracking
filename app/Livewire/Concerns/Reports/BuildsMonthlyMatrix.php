<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Reports;

use App\Enums\DenominatorBasis;
use App\Enums\DonorAttribution;
use App\Enums\IndicatorMeasure;
use App\Enums\MatrixCellState;
use App\Enums\MonthlyReportStatus;
use App\Models\Donor;
use App\Models\HrCenter;
use App\Models\HrCity;
use App\Models\LibraryIndicator;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\StrategicPillar;
use App\Support\MealCoverage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;

/**
 * The Monthly Matrix's one read (T18, contracts §13, §14, §14.1): the rows a
 * viewer's filters select, their twelve month cells, and their targets,
 * totals and achieved percentages.
 *
 * Used by reports/monthly-matrix only. A flat set of queries, whatever the
 * row count: the target rows, the monthly reports of the window, the lines
 * (center view) or share mirrors (donor view) in them, and one pooled-ratio
 * aggregate. Nothing is computed from the visible cells: counts total the
 * validated cells' own figures and ratios pool only through
 * PlanIndicator::pooledRatioSql().
 *
 * The page provides the #[Url] filters below and WithPagination's getPage().
 *
 * @property string $proposal #[Url] filter on the page
 * @property string $donor #[Url] filter on the page
 * @property string $center #[Url] filter on the page (a project id)
 * @property string $city #[Url] filter on the page
 * @property string $indicator #[Url] filter on the page (a library entry id)
 * @property string $pillar #[Url] filter on the page
 * @property string $year #[Url] filter on the page
 * @property bool $mine #[Url] filter on the page
 * @property string $view #[Url] mode on the page: rows | grouped
 * @property string $tab #[Url] tab on the page: matrix | activities
 * @property bool $pwd #[Url] show the disability rows of a breakdown
 * @property-read array{proposal: ?int, donor: ?int, center: ?int, city: ?int, indicator: ?int, pillar: ?int} $resolvedFilters
 * @property-read array{months: list<CarbonImmutable>, rows: list<array<string, mixed>>, isDonorView: bool} $matrix
 */
trait BuildsMonthlyMatrix
{
    /** How many matrix rows one page holds; the export holds them all. */
    private const PER_PAGE = 100;

    /**
     * The filter values that name a record that exists, as ids; an unknown
     * or malformed one resolves to null ("all").
     *
     * @return array{proposal: ?int, donor: ?int, center: ?int, city: ?int, indicator: ?int, pillar: ?int}
     */
    #[Computed]
    public function resolvedFilters(): array
    {
        return [
            'proposal' => $this->existingId($this->proposal, Proposal::class),
            'donor' => $this->existingId($this->donor, Donor::class),
            'center' => $this->existingId($this->center, Project::class),
            'city' => $this->existingId($this->city, null, $this->cityOptions->pluck('id')->all()),
            'indicator' => $this->existingId($this->indicator, LibraryIndicator::class),
            'pillar' => $this->existingId($this->pillar, StrategicPillar::class),
        ];
    }

    /**
     * The proposal the window follows: the one filtered proposal's period.
     *
     * @return array{begin: CarbonImmutable, years: int}|null
     */
    #[Computed]
    public function proposalPeriod(): ?array
    {
        $id = $this->resolvedFilters['proposal'];
        $proposal = $id === null ? null : Proposal::query()->select(['id', 'actual_date_begin', 'actual_date_end', 'planned_date_begin', 'planned_date_end'])->find($id);
        $begin = $proposal === null ? null : ($proposal->actual_date_begin ?? $proposal->planned_date_begin);

        if ($begin === null) {
            return null;
        }

        $end = $proposal->actual_date_end ?? $proposal->planned_date_end ?? $begin->addYear();
        $months = (int) $begin->startOfMonth()->diffInMonths($end->startOfMonth()) + 1;

        return ['begin' => CarbonImmutable::instance($begin)->startOfMonth(), 'years' => max(1, (int) ceil($months / 12))];
    }

    /**
     * The mode the matrix tab shows: rows (default) or grouped by indicator.
     */
    #[Computed]
    public function resolvedView(): string
    {
        return $this->view === 'grouped' ? 'grouped' : 'rows';
    }

    /**
     * The tab shown: the matrix (default) or the Activities board.
     */
    #[Computed]
    public function resolvedTab(): string
    {
        return $this->tab === 'activities' ? 'activities' : 'matrix';
    }

    /**
     * The year the twelve columns show, resolved: a grant year when one
     * proposal is filtered, else a calendar year. A stray value falls back
     * to the first grant year or the current calendar year.
     */
    #[Computed]
    public function selectedYear(): int
    {
        $requested = ctype_digit($this->year) ? (int) $this->year : 0;
        $period = $this->proposalPeriod;

        if ($period !== null) {
            return $requested >= 1 && $requested <= $period['years'] ? $requested : 1;
        }

        $current = CarbonImmutable::today()->year;

        return $requested >= 2020 && $requested <= $current + 1 ? $requested : $current;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    #[Computed]
    public function yearOptions(): array
    {
        $period = $this->proposalPeriod;

        if ($period !== null) {
            return array_map(
                fn (int $grantYear): array => ['value' => (string) $grantYear, 'label' => __('reports.matrix.grant_year', ['year' => $grantYear])],
                range(1, $period['years']),
            );
        }

        return array_map(
            fn (int $year): array => ['value' => (string) $year, 'label' => (string) $year],
            range(2020, CarbonImmutable::today()->year + 1),
        );
    }

    /**
     * The twelve month starts the columns show.
     *
     * @return list<CarbonImmutable>
     */
    #[Computed]
    public function months(): array
    {
        $period = $this->proposalPeriod;
        $start = $period !== null
            ? $period['begin']->addMonths(($this->selectedYear - 1) * 12)
            : CarbonImmutable::parse($this->selectedYear.'-01-01');

        return array_map(fn (int $offset): CarbonImmutable => $start->addMonths($offset), range(0, 11));
    }

    /**
     * Donor view when a donor or proposal is filtered, else the center view
     * (contracts §14.1).
     */
    protected function isDonorView(): bool
    {
        return $this->resolvedFilters['donor'] !== null || $this->resolvedFilters['proposal'] !== null;
    }

    /**
     * The matrix: the months and every row the filters select, in display
     * order. Read once; the page pages it in memory and the export reads it
     * whole.
     *
     * @return array{months: list<CarbonImmutable>, rows: list<array<string, mixed>>, isDonorView: bool}
     */
    #[Computed]
    public function matrix(): array
    {
        $months = $this->months;
        $isDonorView = $this->isDonorView();
        $rows = $this->matrixRows($months, $isDonorView);

        if ($rows !== []) {
            $rows = $this->withCells($rows, $months, $isDonorView);
            $rows = $this->withTotals($rows, $months, $isDonorView);
            $rows = $this->withBreakdowns($rows, $months, $isDonorView);

            if ($this->resolvedView === 'grouped') {
                $rows = $this->groupRows($rows, $months, $isDonorView);
            }
        }

        return ['months' => $months, 'rows' => $rows, 'isDonorView' => $isDonorView];
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    #[Computed]
    public function pagedRows(): LengthAwarePaginator
    {
        $rows = collect($this->matrix['rows']);
        $page = max(1, (int) $this->getPage());

        return new LengthAwarePaginator(
            $rows->forPage($page, self::PER_PAGE)->values(),
            $rows->count(),
            self::PER_PAGE,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath(), 'pageName' => 'page'],
        );
    }

    /**
     * Whether "my projects" is on but the viewer covers none.
     */
    #[Computed]
    public function hasNoAssignedProjects(): bool
    {
        return $this->mine && $this->coveredProjectIds() === [];
    }

    /**
     * @return Collection<int, object{id: int, name: string}>
     */
    #[Computed]
    public function proposalOptions(): Collection
    {
        return DB::table('proposals')->orderBy('name')->orderBy('id')->get(['id', 'name']);
    }

    /**
     * @return Collection<int, object{id: int, name: string}>
     */
    #[Computed]
    public function donorOptions(): Collection
    {
        return DB::table('donors')->orderBy('name')->orderBy('id')->get(['id', 'name']);
    }

    /**
     * @return Collection<int, object{id: int, name: string}>
     */
    #[Computed]
    public function projectOptions(): Collection
    {
        return DB::table('projects')->orderBy('name')->orderBy('id')->get(['id', 'name']);
    }

    /**
     * @return Collection<int, object{id: int, code: string, name_ar: string, name_en: ?string}>
     */
    #[Computed]
    public function indicatorOptions(): Collection
    {
        return DB::table('library_indicators')->orderBy('code')->get(['id', 'code', 'name_ar', 'name_en']);
    }

    /**
     * @return Collection<int, object{id: int, name_ar: string, name_en: ?string}>
     */
    #[Computed]
    public function pillarOptions(): Collection
    {
        return DB::table('strategic_pillars')->orderBy('sort_order')->orderBy('id')->get(['id', 'name_ar', 'name_en']);
    }

    /**
     * Every active HR city, by name.
     *
     * @return Collection<int, HrCity>
     */
    #[Computed]
    public function cityOptions(): Collection
    {
        return HrCity::query()->where('is_active', true)->orderBy(HrCity::nameColumn())->orderBy('id')->get(['id', 'name_en', 'name_ar']);
    }

    /**
     * A number as a plain figure: no trailing zeros.
     */
    protected function matrixNumber(?float $value): string
    {
        return $value === null ? '' : rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /**
     * The id a filter value names when that record exists, else null.
     *
     * @param  class-string<Model>|null  $model
     * @param  list<int>|null  $allowed  a fixed list to check against instead of a table
     */
    protected function existingId(string $value, ?string $model, ?array $allowed = null): ?int
    {
        if ($value === '' || ! ctype_digit($value) || strlen($value) > 18) {
            return null;
        }

        $id = (int) $value;

        if ($allowed !== null) {
            return in_array($id, array_map('intval', $allowed), true) ? $id : null;
        }

        return $model !== null && $model::query()->whereKey($id)->exists() ? $id : null;
    }

    /**
     * The project ids "my projects" narrows to (contracts §12.3, D-12a).
     *
     * @return list<int>
     */
    protected function coveredProjectIds(): array
    {
        $employeeId = Auth::user()?->employee_id;

        return $employeeId === null ? [] : MealCoverage::projectsCoveredBy((int) $employeeId);
    }

    /**
     * The target rows the filters select, grouped into matrix rows (one per
     * project and library entry in the center view, one per target in the
     * donor view), each with its sources, target and display labels.
     *
     * @param  list<CarbonImmutable>  $months
     * @return list<array<string, mixed>>
     */
    protected function matrixRows(array $months, bool $isDonorView): array
    {
        $targets = $this->targetQuery($months[0], $months[11]->endOfMonth())->get();

        if ($targets->isEmpty()) {
            return [];
        }

        $centers = [];
        foreach ($targets->pluck('center_id')->filter()->unique() as $centerId) {
            $centers[(int) $centerId] = HrCenter::remembered((int) $centerId);
        }

        $cityIds = $targets->map(fn (object $row): ?int => $this->cityIdOf($row, $centers))->filter()->unique()->values()->all();
        $cities = $cityIds === [] ? collect() : HrCity::query()->whereIn('id', $cityIds)->get(['id', 'name_en', 'name_ar'])->keyBy('id');

        $grouped = $targets->groupBy(fn (object $row): string => $isDonorView ? 'p'.$row->plan_id : $row->project_id.'|'.$row->library_id);

        $rows = $grouped->map(function (Collection $group) use ($centers, $cities, $isDonorView): array {
            $first = $group->first();
            $center = $first->center_id === null ? null : ($centers[(int) $first->center_id] ?? null);
            $cityId = $this->cityIdOf($first, $centers);
            $attribution = $first->donor_attribution === null ? DonorAttribution::Shared : DonorAttribution::from($first->donor_attribution);
            $measure = IndicatorMeasure::from($first->measure);

            // `shared`: one representative target (the highest, the lowest id on a tie); `split`: all.
            $pooledIds = $isDonorView || $attribution === DonorAttribution::Split
                ? $group->pluck('plan_id')->map(fn (mixed $id): int => (int) $id)->all()
                : [(int) $group->sort(fn (object $a, object $b): int => [(float) $b->target, $a->plan_id] <=> [(float) $a->target, $b->plan_id])->first()->plan_id];

            return [
                'key' => $isDonorView ? 'target-'.$first->plan_id : 'row-'.$first->project_id.'-'.$first->library_id,
                'project_id' => (int) $first->project_id,
                'project_name' => $first->project_name,
                'project_slug' => $first->project_slug,
                'library_id' => (int) $first->library_id,
                'code' => $first->code,
                'name_en' => $first->name_en ?? $first->name_ar,
                'name_ar' => $first->name_ar,
                'is_active' => (bool) $first->is_active,
                'measure' => $measure,
                'unit' => $first->unit,
                'is_cumulative' => (bool) $first->is_cumulative,
                'is_disaggregated' => (bool) $first->is_disaggregated,
                'attribution' => $attribution,
                'is_group' => false,
                'centers_count' => 1,
                'breakdown' => null,
                'breakdown_kind' => null,
                'breakdown_raw' => [],
                'denominator_basis' => $first->denominator_basis === null ? null : DenominatorBasis::from($first->denominator_basis),
                'center' => $center?->name ?? $first->project_name,
                'city' => $center === null || $cityId === null ? null : ($cities[$cityId]->name ?? null),
                'sources' => $group->map(fn (object $row): array => [
                    'proposal' => $row->proposal_name,
                    'proposal_slug' => $row->proposal_slug,
                    'donor' => $row->donor_name,
                    'donor_slug' => $row->donor_slug,
                ])->unique(fn (array $source): string => json_encode($source))->values()->all(),
                'target_ids' => $group->pluck('plan_id')->map(fn (mixed $id): int => (int) $id)->all(),
                'pooled_ids' => $pooledIds,
                'target' => $attribution->combine($group->map(fn (object $row): float => (float) $row->target)->all()),
                'cells' => [],
                'total' => null,
                'excluded' => 0,
                'achieved' => null,
            ];
        });

        return $rows->values()->all();
    }

    /**
     * The city a row's center sits in: the center's city, else the
     * project's own.
     *
     * @param  array<int, ?HrCenter>  $centers
     */
    protected function cityIdOf(object $row, array $centers): ?int
    {
        $centerCity = $row->center_id === null ? null : ($centers[(int) $row->center_id]?->city_id ?? null);
        $cityId = $centerCity ?? $row->city_id;

        return $cityId === null ? null : (int) $cityId;
    }

    /**
     * The proposal, donor, center, city, pillar and "my projects" filters on
     * a query that joins `proposals as pp` and `projects as pr`; shared by
     * the matrix and the Activities tab.
     */
    protected function applyMatrixFilters(Builder $query): Builder
    {
        $filters = $this->resolvedFilters;

        $query
            ->when($filters['proposal'] !== null, fn (Builder $q) => $q->where('pp.id', $filters['proposal']))
            ->when($filters['donor'] !== null, fn (Builder $q) => $q->where('pp.donor_id', $filters['donor']))
            ->when($filters['center'] !== null, fn (Builder $q) => $q->where('pr.id', $filters['center']))
            ->when($filters['city'] !== null, fn (Builder $q) => $q->where('pr.city_id', $filters['city']))
            ->when($filters['pillar'] !== null, fn (Builder $q) => $q->whereIn('pr.program_id', Program::query()->where('strategic_pillar_id', $filters['pillar'])->select('id')));

        if ($this->mine) {
            $covered = $this->coveredProjectIds();
            $covered === [] ? $query->whereRaw('1 = 0') : $query->whereIn('pr.id', $covered);
        }

        return $query;
    }

    /**
     * Every target the filters select whose source covers the window, with
     * its library entry, project, proposal, donor and program joined in one
     * read. Targets without a library entry carry no lines and so no row.
     */
    protected function targetQuery(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        $filters = $this->resolvedFilters;

        $query = DB::table('plan_indicators as p')
            ->join('indicators as ind', 'ind.id', '=', 'p.indicator_id')
            ->join('library_indicators as li', 'li.id', '=', 'ind.library_indicator_id')
            ->join('projects as pr', 'pr.id', '=', 'p.project_id')
            ->leftJoin('programs as pg', 'pg.id', '=', 'pr.program_id')
            ->leftJoin('proposals as pp', fn ($join) => $join->on('pp.id', '=', 'p.source_id')->where('p.source_type', '=', 'proposal'))
            ->leftJoin('donors as d', 'd.id', '=', 'pp.donor_id')
            ->leftJoin('annual_plans as ap', fn ($join) => $join->on('ap.id', '=', 'p.source_id')->where('p.source_type', '=', 'annual_plan'))
            ->where(function (Builder $covers) use ($from, $to): void {
                $covers->where(function (Builder $proposalTargets) use ($from, $to): void {
                    $proposalTargets->whereNotNull('pp.id')
                        ->whereRaw('(case when pp.actual_date_begin is not null and pp.actual_date_end is not null then pp.actual_date_begin else pp.planned_date_begin end) <= ?', [$to->toDateString()])
                        ->whereRaw('(case when pp.actual_date_begin is not null and pp.actual_date_end is not null then pp.actual_date_end else pp.planned_date_end end) >= ?', [$from->toDateString()]);
                })->orWhere(function (Builder $planTargets) use ($from, $to): void {
                    $planTargets->whereNotNull('ap.id')->whereBetween('ap.year', [$from->year, $to->year]);
                });
            })
            ->when($filters['indicator'] !== null, fn (Builder $q) => $q->where('li.id', $filters['indicator']));

        return $this->applyMatrixFilters($query)
            ->orderBy('pr.name')
            ->orderBy('pr.id')
            ->orderBy('li.code')
            ->orderBy('p.id')
            ->select([
                'p.id as plan_id', 'p.target', 'p.project_id',
                'li.id as library_id', 'li.code', 'li.name_ar', 'li.name_en', 'li.unit', 'li.is_cumulative', 'li.is_disaggregated', 'li.denominator_basis', 'li.is_active',
                'ind.measure',
                'pr.name as project_name', 'pr.slug as project_slug', 'pr.center_id', 'pr.city_id',
                'pg.donor_attribution',
                'pp.name as proposal_name', 'pp.slug as proposal_slug',
                'd.name as donor_name', 'd.slug as donor_slug',
            ]);
    }

    /**
     * Fills each row's twelve cells from the monthly reports and their
     * lines (center view) or share mirrors (donor view).
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<CarbonImmutable>  $months
     * @return list<array<string, mixed>>
     */
    protected function withCells(array $rows, array $months, bool $isDonorView): array
    {
        $from = $months[0]->toDateString();
        $to = $months[11]->toDateString();
        $projectIds = array_values(array_unique(array_column($rows, 'project_id')));

        /** @var array<int, array<string, string>> $reportStatus project id => Y-m => monthly report status */
        $reportStatus = [];
        foreach (DB::table('monthly_reports')->whereIn('project_id', $projectIds)->whereBetween('period', [$from, $to])->get(['project_id', 'period', 'status']) as $report) {
            $reportStatus[(int) $report->project_id][substr((string) $report->period, 0, 7)] = (string) $report->status;
        }

        /** @var array<string, array<string, object>> $figures row key => Y-m => the line or mirror figures */
        $figures = [];

        if ($isDonorView) {
            $targetKey = [];
            foreach ($rows as $row) {
                $targetKey[$row['target_ids'][0]] = $row['key'];
            }

            $shares = DB::table('monthly_report_line_shares as s')
                ->join('monthly_report_lines as l', 'l.id', '=', 's.monthly_report_line_id')
                ->join('monthly_reports as mr', 'mr.id', '=', 'l.monthly_report_id')
                ->leftJoin('reports as r', 'r.monthly_report_line_share_id', '=', 's.id')
                ->whereIn('s.plan_indicator_id', array_keys($targetKey))
                ->whereBetween('mr.period', [$from, $to])
                ->get(['s.plan_indicator_id', 'mr.period', 'r.value', 'r.numerator', 'r.denominator', 's.not_measured']);

            foreach ($shares as $share) {
                $figures[$targetKey[(int) $share->plan_indicator_id]][substr((string) $share->period, 0, 7)] = $share;
            }
        } else {
            $libraryIds = array_values(array_unique(array_column($rows, 'library_id')));

            $lines = DB::table('monthly_report_lines as l')
                ->join('monthly_reports as mr', 'mr.id', '=', 'l.monthly_report_id')
                ->whereIn('mr.project_id', $projectIds)
                ->whereIn('l.library_indicator_id', $libraryIds)
                ->whereBetween('mr.period', [$from, $to])
                ->get(['mr.project_id', 'mr.period', 'l.library_indicator_id', 'l.value', 'l.numerator', 'l.denominator', 'l.not_measured']);

            foreach ($lines as $line) {
                $figures['row-'.$line->project_id.'-'.$line->library_indicator_id][substr((string) $line->period, 0, 7)] = $line;
            }
        }

        $today = CarbonImmutable::today();

        foreach ($rows as $index => $row) {
            foreach ($months as $month) {
                $ym = $month->format('Y-m');
                $status = $reportStatus[$row['project_id']][$ym] ?? null;
                $figure = $figures[$row['key']][$ym] ?? null;

                $state = match (true) {
                    $month->greaterThan($today) => MatrixCellState::Upcoming,
                    $status === null => MatrixCellState::NotSubmitted,
                    $figure === null => MatrixCellState::NotDue,
                    $status === MonthlyReportStatus::Validated->value => MatrixCellState::Approved,
                    $status === MonthlyReportStatus::Submitted->value => MatrixCellState::Pending,
                    default => MatrixCellState::NotSubmitted,
                };
                $shown = $figure !== null && in_array($state, [MatrixCellState::Approved, MatrixCellState::Pending], true);

                $rows[$index]['cells'][$ym] = [
                    'state' => $state,
                    'value' => $shown && $figure->value !== null ? (float) $figure->value : null,
                    'numerator' => $shown && $figure->numerator !== null ? (float) $figure->numerator : null,
                    'denominator' => $shown && $figure->denominator !== null ? (float) $figure->denominator : null,
                    'not_measured' => $shown && (bool) $figure->not_measured,
                ];
            }
        }

        return $rows;
    }

    /**
     * Fills each row's total, the ratios' excluded count and the achieved
     * percentage. Counts total the validated cells' figures (the latest one
     * when cumulative); ratios pool through PlanIndicator::pooledRatioSql().
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<CarbonImmutable>  $months
     * @return list<array<string, mixed>>
     */
    protected function withTotals(array $rows, array $months, bool $isDonorView): array
    {
        $pooled = $this->pooledRatios($rows, $months[0], $months[11]->endOfMonth(), $isDonorView);

        foreach ($rows as $index => $row) {
            $total = null;
            $excluded = 0;

            if ($row['measure'] === IndicatorMeasure::Numeric) {
                $validated = collect($row['cells'])
                    ->filter(fn (array $cell): bool => $cell['state'] === MatrixCellState::Approved && $cell['value'] !== null)
                    ->pluck('value');
                $total = (float) ($row['is_cumulative'] ? ($validated->last() ?? 0.0) : $validated->sum());
            } elseif (isset($pooled[$row['key']])) {
                $ratio = $pooled[$row['key']]['ratio'];
                $total = $ratio === null ? null : round($ratio, 2);
                $excluded = $pooled[$row['key']]['excluded'];
            }

            $rows[$index]['total'] = $total;
            $rows[$index]['excluded'] = $excluded;
            $rows[$index]['achieved'] = $total === null || $row['target'] <= 0.0 ? null : round($total / $row['target'] * 100, 2);
        }

        return $rows;
    }

    /**
     * The pooled level of every percentage or average row over the window,
     * one aggregate over the rows' targets (contracts §13 Pooling): the
     * representative target in a `shared` center row, every target in a
     * `split` one, the single target in the donor view. `$byLibrary` pools all
     * the targets of a library entry together (the grouped mode).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array{ratio: ?float, excluded: int}>
     */
    protected function pooledRatios(array $rows, CarbonImmutable $from, CarbonImmutable $to, bool $isDonorView, bool $byLibrary = false): array
    {
        $ratioRows = array_values(array_filter($rows, fn (array $row): bool => $row['measure'] !== IndicatorMeasure::Numeric));

        if ($ratioRows === []) {
            return [];
        }

        $rowKeyOfTarget = [];
        foreach ($ratioRows as $row) {
            foreach ($row['pooled_ids'] as $targetId) {
                $rowKeyOfTarget[$targetId] = $row['key'];
            }
        }

        $grammar = DB::connection()->getQueryGrammar();
        $pooled = PlanIndicator::query()
            ->toBase()
            ->from('plan_indicators')
            ->whereIn('plan_indicators.id', array_keys($rowKeyOfTarget))
            ->groupBy(...$this->pooledGroupColumns($isDonorView, $byLibrary))
            ->selectRaw('min(plan_indicators.id) as first_target_id, '.PlanIndicator::pooledRatioSql($from, $to)->getValue($grammar).' as ratio, '.PlanIndicator::pooledExcludedCountSql($from, $to)->getValue($grammar).' as excluded')
            ->get();

        $result = [];
        foreach ($pooled as $group) {
            $result[$rowKeyOfTarget[(int) $group->first_target_id]] = [
                'ratio' => $group->ratio === null ? null : (float) $group->ratio,
                'excluded' => (int) $group->excluded,
            ];
        }

        return $result;
    }

    /**
     * The columns one pooled ratio is grouped by: a target (donor view), a
     * center's library entry (center view) or a library entry (grouped).
     *
     * @return list<Expression|string>
     */
    protected function pooledGroupColumns(bool $isDonorView, bool $byLibrary): array
    {
        $library = DB::raw('(select i2.library_indicator_id from indicators i2 where i2.id = plan_indicators.indicator_id)');

        return match (true) {
            $byLibrary => [$library],
            $isDonorView => ['plan_indicators.project_id', 'plan_indicators.id'],
            default => ['plan_indicators.project_id', $library],
        };
    }
}
