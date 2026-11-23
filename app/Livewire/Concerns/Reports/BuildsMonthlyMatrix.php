<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Reports;

use App\Enums\DenominatorBasis;
use App\Enums\DonorAttribution;
use App\Enums\IndicatorMeasure;
use App\Enums\MatrixCellState;
use App\Enums\MonthlyReportStatus;
use App\Livewire\Concerns\FiltersReportPlaces;
use App\Models\Donor;
use App\Models\HrCity;
use App\Models\LibraryIndicator;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Proposal;
use App\Models\ReportTemplate;
use App\Models\StrategicPillar;
use App\Support\MealCoverage;
use App\Support\Reporting\ExtractionFilter;
use App\Support\Reporting\PlaceBridge;
use App\Support\Reporting\ReportExtraction;
use App\Support\Reporting\ReportPlace;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use stdClass;

/**
 * The Monthly Matrix's one read (T18, Y-05, contracts §13, §14, §14.1): the
 * rows a viewer's filters select, their month cells, and their targets,
 * totals and achieved percentages, all read through ReportExtraction (D-79):
 * nothing here builds its own `where`, so the screen and the file agree.
 *
 * Center view: one row per place (the template's city, program and center,
 * or Field work, or Center not set) and library entry; two templates at one
 * place are one row. Donor view: one row per target. Totals come from
 * ReportExtraction::centerTotals() / targetTotals(); ratios pool only
 * through PlanIndicator's pooled readers. A flat set of queries whatever
 * the number of places, months and indicators.
 *
 * The page provides the #[Url] filters below and WithPagination's getPage().
 *
 * @property string $proposal #[Url] filter on the page
 * @property string $donor #[Url] filter on the page
 * @property string $center #[Url] filter on the page: c{hrCenterId}, field-{cityId} or unset
 * @property string $city #[Url] filter on the page
 * @property string $program #[Url] filter on the page
 * @property string $indicator #[Url] filter on the page (a library entry id)
 * @property string $pillar #[Url] filter on the page
 * @property string $from #[Url] first month on the page (Y-m)
 * @property string $to #[Url] last month on the page (Y-m)
 * @property string $year #[Url] legacy year, translated on mount
 * @property bool $mine #[Url] filter on the page
 * @property string $view #[Url] mode on the page: rows | grouped
 * @property string $tab #[Url] tab on the page: matrix | activities
 * @property bool $pwd #[Url] show the disability rows of a breakdown
 * @property list<string> $filterOrder the filters in the order the viewer applied them
 * @property-read array{proposal: ?int, donor: ?int, city: ?int, program: ?int, indicator: ?int, pillar: ?int} $resolvedFilters
 * @property-read ExtractionFilter $extractionFilter
 * @property-read list<CarbonImmutable> $months
 * @property-read array{months: list<CarbonImmutable>, rows: list<array<string, mixed>>, isDonorView: bool} $matrix
 * @property-read array{begin: CarbonImmutable, end: CarbonImmutable}|null $proposalPeriod
 * @property-read bool $hasWholeGrant
 * @property-read string $resolvedView
 * @property-read string $resolvedTab
 * @property-read LengthAwarePaginator<int, array<string, mixed>> $pagedRows
 * @property-read bool $hasNoAssignedProjects
 * @property-read string|null $emptyReason
 * @property-read Collection<int, stdClass> $proposalOptions
 * @property-read Collection<int, stdClass> $donorOptions
 * @property-read Collection<int, stdClass> $programOptions
 * @property-read list<array{value: string, label: string}> $centerOptions
 * @property-read Collection<int, stdClass> $indicatorOptions
 * @property-read Collection<int, stdClass> $pillarOptions
 * @property-read Collection<int, HrCity> $cityOptions
 */
trait BuildsMonthlyMatrix
{
    use FiltersReportPlaces;

    /** How many matrix rows one page holds; the export holds them all. */
    private const PER_PAGE = 100;

    /** The filters emptyReason() can name, in their default order. */
    private const FILTER_NAMES = ['proposal', 'donor', 'city', 'program', 'center', 'indicator', 'pillar', 'window', 'mine'];

    /** @var array<string, ReportExtraction> */
    private array $extractionMemo = [];

    /**
     * The id filters that name a record that exists; an unknown or
     * malformed one resolves to null ("all").
     *
     * @return array{proposal: ?int, donor: ?int, city: ?int, program: ?int, indicator: ?int, pillar: ?int}
     */
    #[Computed]
    public function resolvedFilters(): array
    {
        return [
            'proposal' => $this->existingId($this->proposal, Proposal::class),
            'donor' => $this->existingId($this->donor, Donor::class),
            'city' => $this->existingId($this->city, null, array_values($this->allCities()->keys()->all())),
            'program' => $this->existingId($this->program, Program::class),
            'indicator' => $this->existingId($this->indicator, LibraryIndicator::class),
            'pillar' => $this->existingId($this->pillar, StrategicPillar::class),
        ];
    }

    /**
     * The page's filters as the extraction reads them. A Field work center
     * brings its own city; an unknown center is ignored.
     */
    #[Computed]
    public function extractionFilter(): ExtractionFilter
    {
        return new ExtractionFilter(...$this->filterArguments(self::FILTER_NAMES));
    }

    /**
     * The months the columns show: the window, inclusive (it may run past
     * twelve months). Empty while From is after To.
     *
     * @return list<CarbonImmutable>
     */
    #[Computed]
    public function months(): array
    {
        return $this->windowIsReversed() ? [] : $this->extraction()->months();
    }

    /**
     * The one filtered proposal's period: actual begin (else planned) to its
     * effective end (else planned).
     *
     * @return array{begin: CarbonImmutable, end: CarbonImmutable}|null
     */
    #[Computed]
    public function proposalPeriod(): ?array
    {
        $id = $this->resolvedFilters['proposal'];
        $proposal = $id === null ? null : Proposal::query()->select(['id', 'actual_date_begin', 'actual_date_end', 'extended_date_end', 'planned_date_begin', 'planned_date_end'])->find($id);
        $begin = $proposal === null ? null : ($proposal->actual_date_begin ?? $proposal->planned_date_begin);

        if ($begin === null) {
            return null;
        }

        $end = $proposal->effectiveDateEnd() ?? $proposal->planned_date_end ?? $begin->addYear()->subDay();

        return ['begin' => CarbonImmutable::instance($begin)->startOfMonth(), 'end' => CarbonImmutable::instance($end)->startOfMonth()];
    }

    /**
     * Whether exactly one proposal is filtered, so "Whole grant" applies.
     */
    #[Computed]
    public function hasWholeGrant(): bool
    {
        return $this->proposalPeriod !== null;
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
        $isDonorView = $this->extractionFilter->isDonorView();
        $rows = $months === [] ? [] : $this->matrixRows($isDonorView);

        if ($rows !== []) {
            $rows = $this->withCells($rows, $months, $isDonorView);
            $rows = $this->withTotals($rows, $isDonorView);
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
        return $this->mine && ! $this->hasCoveredPlaces();
    }

    /**
     * The name of the filter that emptied the result (proposal, donor, city,
     * program, center, indicator, pillar, window or mine), or null when the
     * result is not empty or no single filter emptied it. The filters are
     * added back one by one in the order the viewer applied them; the first
     * one after which nothing is left is named.
     */
    #[Computed]
    public function emptyReason(): ?string
    {
        if ($this->windowIsReversed()) {
            return 'window';
        }

        $onActivities = $this->resolvedTab === 'activities';
        $isEmpty = $onActivities
            ? $this->activityBoard['outputs'] === []
            : $this->matrix['rows'] === [];

        if (! $isEmpty) {
            return null;
        }

        $active = array_values(array_filter(self::FILTER_NAMES, fn (string $name): bool => $this->filterIsActive($name)));
        $order = array_values(array_unique([...array_intersect($this->filterOrder, $active), ...$active]));
        $applied = [];

        foreach (array_values(array_unique(['window', ...$order])) as $name) {
            $applied[] = $name;
            $filter = new ExtractionFilter(...$this->filterArguments($applied));

            if ($this->selectsNothing($filter, $onActivities)) {
                return $name === 'window' && ! $this->filterIsActive('window') ? null : $name;
            }
        }

        return null;
    }

    /**
     * @return Collection<int, stdClass> rows of id, name
     */
    #[Computed]
    public function proposalOptions(): Collection
    {
        return DB::table('proposals')->orderBy('name')->orderBy('id')->get(['id', 'name']);
    }

    /**
     * @return Collection<int, stdClass> rows of id, name
     */
    #[Computed]
    public function donorOptions(): Collection
    {
        return DB::table('donors')->orderBy('name')->orderBy('id')->get(['id', 'name']);
    }

    /**
     * Every program, by its HR name.
     *
     * @return Collection<int, stdClass> rows of id, name
     */
    #[Computed]
    public function programOptions(): Collection
    {
        return collect(Program::options()->all())->map(fn (Program $program): stdClass => self::programOption($program))->values();
    }

    /**
     * A program as an option row (id, name), the shape of the other options.
     */
    private static function programOption(Program $program): stdClass
    {
        $option = new stdClass;
        $option->id = $program->id;
        $option->name = $program->name;

        return $option;
    }

    /**
     * The places a viewer can pick (D-79, D-82, D-88): each HR center that
     * holds at least one report template ("center · city"), one Field work
     * option per city with a city-wide template, and Center not set when a
     * template has no center; narrowed by the City and Program filters.
     *
     * @return list<array{value: string, label: string}>
     */
    #[Computed]
    public function centerOptions(): array
    {
        $filters = $this->resolvedFilters;

        return $this->centerOptionsFor($filters['city'], $filters['program']);
    }

    /**
     * @return Collection<int, stdClass> rows of id, code, name_ar, name_en
     */
    #[Computed]
    public function indicatorOptions(): Collection
    {
        return DB::table('library_indicators')->orderBy('code')->get(['id', 'code', 'name_ar', 'name_en']);
    }

    /**
     * @return Collection<int, stdClass> rows of id, name_ar, name_en
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
        return $this->allCities()->filter(fn (HrCity $city): bool => $city->is_active)->values();
    }

    /**
     * The monthly-reports list at one month of a row (Y-05): the page's
     * proposal, the row's place (or the page's place filters when the row
     * spans several places or has none) and every status; null when the cell
     * shows no monthly report. Built from the row alone: no query.
     *
     * @param  array<string, mixed>  $row
     */
    protected function cellListUrl(array $row, CarbonImmutable $month): ?string
    {
        $state = $row['cells'][$month->format('Y-m')]['state'] ?? null;

        if (! in_array($state, [MatrixCellState::Approved, MatrixCellState::Pending, MatrixCellState::NotDue], true)) {
            return null;
        }

        $place = count($row['place_keys']) === 1 ? self::placeOfKey($row['place_keys'][0]) : null;
        $place = $place === null
            ? ['city' => $this->resolvedFilters['city'], 'program' => $this->resolvedFilters['program'], 'center' => $this->centerArguments() === [] ? null : $this->center]
            : ['city' => $place->cityId, 'program' => $place->programId, 'center' => $this->centerValueOf($place)];

        return route('monthly-reports.index', array_filter([
            'proposal' => $this->resolvedFilters['proposal'],
            ...$place,
            'from' => $month->format('Y-m'),
            'to' => $month->format('Y-m'),
            'status' => 'all',
        ], fn (mixed $value): bool => $value !== null && $value !== ''));
    }

    /**
     * The place a ReportPlace::key() names: "city:program:c{id}",
     * "city:program:city-wide" or "city:program:unset".
     */
    private static function placeOfKey(string $key): ?ReportPlace
    {
        if (preg_match('/^(\d+):(\d+):(c(\d+)|city-wide|unset)$/', $key, $match) !== 1) {
            return null;
        }

        return new ReportPlace((int) $match[1], (int) $match[2], isset($match[4]) ? (int) $match[4] : null, $match[3] === 'city-wide');
    }

    /**
     * A number as a plain figure: no trailing zeros.
     */
    protected function matrixNumber(?float $value): string
    {
        return $value === null ? '' : rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /**
     * Sets the window to January of this year → this month.
     */
    protected function setThisYearWindow(): void
    {
        $today = CarbonImmutable::today();
        $this->from = $today->startOfYear()->format('Y-m');
        $this->to = $today->format('Y-m');
    }

    /**
     * Refuses a From after To with a field error (never swapped).
     */
    protected function checkWindow(): void
    {
        $this->resetErrorBag('from');

        if ($this->windowIsReversed()) {
            $this->addError('from', __('reports.matrix.from_after_to'));
        }
    }

    /**
     * The old links still open (Y-05): `?center=<project id>` becomes that
     * project's place (and its city and program), `?year=` becomes From/To
     * (a calendar year, or grant year N of the one filtered proposal). Both
     * are cleared, so the URL is rewritten.
     */
    protected function translateLegacyFilters(): void
    {
        if ($this->center !== '' && ctype_digit($this->center) && strlen($this->center) <= 18) {
            $place = app(PlaceBridge::class)->placeOfProject((int) $this->center);
            $this->center = $place === null ? '' : $this->centerValueOf($place);

            if ($place !== null) {
                $this->city = (string) $place->cityId;
                $this->program = (string) $place->programId;
            }
        }

        if ($this->year === '') {
            return;
        }

        $year = ctype_digit($this->year) && strlen($this->year) <= 4 ? (int) $this->year : 0;
        $period = $this->proposalPeriod;
        $grantYears = $period === null ? 0 : (int) ceil(((int) $period['begin']->diffInMonths($period['end']) + 1) / 12);

        if ($this->from === '' && $this->to === '') {
            if ($period !== null && $year >= 1 && $year <= $grantYears) {
                $start = $period['begin']->addMonths(($year - 1) * 12);
                $this->from = $start->format('Y-m');
                $this->to = $start->addMonths(11)->format('Y-m');
            } elseif ($year >= 2000 && $year <= 2100) {
                $this->from = $year.'-01';
                $this->to = $year.'-12';
            }
        }

        $this->year = '';
    }

    protected function hasCoveredPlaces(): bool
    {
        $employeeId = Auth::user()?->employee_id;

        return $employeeId !== null && (
            MealCoverage::scopeCoveredBy(ReportTemplate::query(), (int) $employeeId)->exists()
            || MealCoverage::scopeCoveredBy(PlanIndicator::query(), (int) $employeeId)->exists()
        );
    }

    /**
     * The extraction of the page's filters, built once per request.
     */
    protected function extraction(): ReportExtraction
    {
        $filter = $this->extractionFilter;

        return $this->extractionMemo[serialize($filter)] ??= ReportExtraction::for($filter);
    }

    /**
     * Whether From and To are both set and From is after To.
     */
    protected function windowIsReversed(): bool
    {
        $from = $this->monthOf($this->from);
        $to = $this->monthOf($this->to);

        return $from !== null && $to !== null && $from->greaterThan($to);
    }

    /**
     * The extraction filter's arguments for the given filters only.
     *
     * @param  list<string>  $names
     * @return array<string, mixed>
     */
    private function filterArguments(array $names): array
    {
        $filters = $this->resolvedFilters;
        $arguments = [];

        foreach ($names as $name) {
            $arguments = [...$arguments, ...match ($name) {
                'proposal' => ['proposalId' => $filters['proposal']],
                'donor' => ['donorId' => $filters['donor']],
                'city' => ['cityId' => $arguments['cityId'] ?? $filters['city']],
                'program' => ['programId' => $filters['program']],
                'indicator' => ['indicatorId' => $filters['indicator']],
                'pillar' => ['pillarId' => $filters['pillar']],
                'window' => ['from' => $this->monthOf($this->from), 'to' => $this->monthOf($this->to)],
                'mine' => ['mine' => $this->mine],
                'center' => $this->centerArguments(),
                default => [],
            }];
        }

        return $arguments;
    }

    /**
     * The center filter's arguments: c{id} (an HR center that exists),
     * field-{city} (that city's Field work) or unset.
     *
     * @return array<string, mixed>
     */
    private function centerArguments(): array
    {
        return $this->centerArgumentsOf($this->center);
    }

    private function filterIsActive(string $name): bool
    {
        return match ($name) {
            'window' => $this->monthOf($this->from) !== null || $this->monthOf($this->to) !== null,
            'mine' => $this->mine,
            'center' => $this->centerArguments() !== [],
            default => ($this->resolvedFilters[$name] ?? null) !== null,
        };
    }

    /**
     * Whether a filter selects no matrix row (or, on the Activities tab, no
     * proposal and project pair).
     */
    private function selectsNothing(ExtractionFilter $filter, bool $onActivities): bool
    {
        $extraction = ReportExtraction::for($filter);
        $months = $extraction->months();

        if ($months === []) {
            return true;
        }

        if ($onActivities) {
            return $this->activityLinksFor($filter, $months[0], $months[count($months) - 1]->endOfMonth())->isEmpty();
        }

        $query = $extraction->targets();

        if (! $filter->isDonorView()) {
            $query->whereNotNull('t.place_city_id')->whereNotNull('t.place_program_id');
        }

        return ! $query->exists();
    }

    private function placeOfRow(stdClass $row): ?ReportPlace
    {
        return $row->place_city_id === null || $row->place_program_id === null
            ? null
            : new ReportPlace((int) $row->place_city_id, (int) $row->place_program_id, $row->place_center_id === null ? null : (int) $row->place_center_id, (bool) $row->place_city_wide);
    }

    /**
     * The target rows of the extraction, grouped into matrix rows: one per
     * place and library entry (center view, targets without a place have no
     * row) or one per target (donor view), each with its places, sources,
     * target and labels.
     *
     * @return list<array<string, mixed>>
     */
    protected function matrixRows(bool $isDonorView): array
    {
        $targets = $this->extraction()->targetRows();

        if (! $isDonorView) {
            $targets = $targets->filter(fn (stdClass $row): bool => $this->placeOfRow($row) !== null);
        }

        if ($targets->isEmpty()) {
            return [];
        }

        $grouped = $targets->groupBy(fn (stdClass $row): string => $isDonorView
            ? 'target-'.$row->plan_id
            : 'row-'.$this->placeOfRow($row)->key().'-'.$row->library_id);

        $rows = $grouped->map(function (Collection $group, string $key): array {
            $first = $group->first();
            $places = $group->map(fn (stdClass $row): ?ReportPlace => $this->placeOfRow($row))->filter()->unique(fn (ReportPlace $place): string => $place->key())->values();
            $labels = $places->map(fn (ReportPlace $place): array => $this->placeLabel($place));
            $attribution = $first->donor_attribution === null ? DonorAttribution::Shared : DonorAttribution::from($first->donor_attribution);
            $shared = $group->filter(fn (stdClass $row): bool => (bool) $row->is_shared)->pluck('shared_centers')->filter()->map(fn (mixed $count): int => (int) $count);

            return [
                'key' => $key,
                'place_key' => $places->first()?->key(),
                'place_keys' => $places->map(fn (ReportPlace $place): string => $place->key())->all(),
                'center' => $labels->isEmpty() ? __('reports.matrix.place_not_set') : $labels->pluck('center')->unique()->implode(', '),
                'city' => $labels->pluck('city')->filter()->unique()->implode(', ') ?: null,
                'shared_centers' => $shared->isEmpty() ? null : $shared->max(),
                'source_windows' => $group->unique('plan_id')->map(fn (stdClass $target): array => [
                    'start' => $target->plan_year === null ? $target->proposal_start : $target->plan_year.'-01-01',
                    'end' => $target->plan_year === null ? $target->proposal_end : $target->plan_year.'-12-31',
                ])->values()->all(),
                'library_id' => (int) $first->library_id,
                'code' => $first->code,
                'name_en' => $first->name_en ?? $first->name_ar,
                'name_ar' => $first->name_ar,
                'is_active' => (bool) $first->is_active,
                'measure' => IndicatorMeasure::from($first->measure),
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
                'sources' => $group->map(fn (stdClass $row): array => [
                    'proposal' => $row->proposal_name,
                    'proposal_slug' => $row->proposal_slug,
                    'donor' => $row->donor_name,
                    'donor_slug' => $row->donor_slug,
                ])->unique(fn (array $source): string => (string) json_encode($source))->values()->all(),
                'target_ids' => $group->pluck('plan_id')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all(),
                'target' => $attribution->combine(array_values($group->unique('plan_id')->map(fn (stdClass $row): float => (float) $row->target)->all())),
                'cells' => [],
                'total' => null,
                'excluded' => 0,
                'achieved' => null,
            ];
        });

        return array_values($rows->sortBy([
            fn (array $a, array $b): int => strcmp(mb_strtolower((string) $a['city']), mb_strtolower((string) $b['city'])),
            fn (array $a, array $b): int => strcmp(mb_strtolower($a['center']), mb_strtolower($b['center'])),
            fn (array $a, array $b): int => strcmp((string) $a['code'], (string) $b['code']),
            fn (array $a, array $b): int => $a['target_ids'][0] <=> $b['target_ids'][0],
        ])->all());
    }

    /**
     * Fills each row's month cells from the extraction's lines (center view)
     * or share mirrors (donor view), of every status. Several templates in
     * one month (two at one place, or a shared target's centers): the value
     * is Σ their validated figures (Σ the submitted ones while nothing is
     * validated), the state the lowest (NotSubmitted < Pending < Approved).
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<CarbonImmutable>  $months
     * @return list<array<string, mixed>>
     */
    protected function withCells(array $rows, array $months, bool $isDonorView): array
    {
        $extraction = $this->extraction();

        /** @var array<string, array<string, true>> $reported place key => Y-m => a monthly report exists */
        $reported = [];
        foreach ($extraction->reports()->get() as $report) {
            $reported[ReportExtraction::placeKey((int) $report->place_city_id, (int) $report->place_program_id, $report->place_center_id === null ? null : (int) $report->place_center_id, (bool) $report->place_city_wide)][substr((string) $report->period, 0, 7)] = true;
        }

        /** @var array<string, array<string, list<stdClass>>> $figures row key => Y-m => figures */
        $figures = [];

        if ($isDonorView) {
            $shares = $extraction->shares()->whereIn('s.plan_indicator_id', array_merge(...array_column($rows, 'target_ids')))->get();

            foreach ($shares as $share) {
                $figures['target-'.$share->plan_indicator_id][substr((string) $share->period, 0, 7)][] = (object) [
                    'status' => $share->status,
                    'value' => $share->report_value,
                    'numerator' => $share->report_numerator,
                    'denominator' => $share->report_denominator,
                    'not_measured' => $share->not_measured,
                ];
            }
        } else {
            $lines = $extraction->lines()->whereIn('l.library_indicator_id', array_values(array_unique(array_column($rows, 'library_id'))))->get();

            foreach ($lines as $line) {
                $key = 'row-'.ReportExtraction::placeKey((int) $line->place_city_id, (int) $line->place_program_id, $line->place_center_id === null ? null : (int) $line->place_center_id, (bool) $line->place_city_wide).'-'.$line->library_indicator_id;
                $figures[$key][substr((string) $line->period, 0, 7)][] = $line;
            }
        }

        $today = CarbonImmutable::today();

        foreach ($rows as $index => $row) {
            foreach ($months as $month) {
                $ym = $month->format('Y-m');
                $hasReport = array_filter($row['place_keys'], fn (string $place): bool => isset($reported[$place][$ym])) !== [];
                $rows[$index]['cells'][$ym] = $this->cellOf($figures[$row['key']][$ym] ?? [], $hasReport, $month, $today, $row['source_windows'], $row['measure']);
            }
        }

        return $rows;
    }

    /**
     * One month of one row.
     *
     * @param  list<stdClass>  $figures  with status, value, numerator, denominator, not_measured
     * @param  list<array{start: string|null, end: string|null}>  $sourceWindows  the row's target sources' periods
     * @return array{state: MatrixCellState, value: ?float, numerator: ?float, denominator: ?float, not_measured: bool}
     */
    private function cellOf(array $figures, bool $hasReport, CarbonImmutable $month, CarbonImmutable $today, array $sourceWindows, IndicatorMeasure $measure): array
    {
        $monthStart = $month->toDateString();
        $monthEnd = $month->endOfMonth()->toDateString();
        $outsidePeriod = array_filter($sourceWindows, fn (array $window): bool => ($window['start'] === null || $window['start'] <= $monthEnd)
            && ($window['end'] === null || $window['end'] >= $monthStart)) === [];
        $statuses = array_column($figures, 'status');

        $state = match (true) {
            $figures === [] && $outsidePeriod => MatrixCellState::OutsidePeriod,
            $month->greaterThan($today) => MatrixCellState::Upcoming,
            $figures === [] && ! $hasReport => MatrixCellState::NotSubmitted,
            $figures === [] => MatrixCellState::NotDue,
            in_array(MonthlyReportStatus::Draft->value, $statuses, true) => MatrixCellState::NotSubmitted,
            in_array(MonthlyReportStatus::Submitted->value, $statuses, true) => MatrixCellState::Pending,
            default => MatrixCellState::Approved,
        };

        $validated = array_values(array_filter($figures, fn (stdClass $figure): bool => $figure->status === MonthlyReportStatus::Validated->value));
        $used = $validated !== [] ? $validated : ($state === MatrixCellState::Pending
            ? array_values(array_filter($figures, fn (stdClass $figure): bool => $figure->status === MonthlyReportStatus::Submitted->value))
            : []);

        return ['state' => $state, ...$this->figuresOf($used, $measure)];
    }

    /**
     * The figures of one or more lines of a month: counts add up; one ratio
     * line keeps its own value, several pool Σ numerator ÷ Σ denominator.
     *
     * @param  list<stdClass>  $used
     * @return array{value: ?float, numerator: ?float, denominator: ?float, not_measured: bool}
     */
    private function figuresOf(array $used, IndicatorMeasure $measure): array
    {
        $empty = ['value' => null, 'numerator' => null, 'denominator' => null, 'not_measured' => false];

        if ($used === []) {
            return $empty;
        }

        $float = fn (mixed $value): ?float => $value === null ? null : (float) $value;
        $notMeasured = array_filter($used, fn (stdClass $figure): bool => ! (bool) $figure->not_measured) === [];

        if (count($used) === 1) {
            return [
                'value' => $float($used[0]->value),
                'numerator' => $float($used[0]->numerator),
                'denominator' => $float($used[0]->denominator),
                'not_measured' => (bool) $used[0]->not_measured,
            ];
        }

        if ($measure === IndicatorMeasure::Numeric) {
            $values = array_filter(array_map(fn (stdClass $figure): ?float => $float($figure->value), $used), fn (?float $value): bool => $value !== null);

            return [...$empty, 'value' => $values === [] ? null : (float) array_sum($values), 'not_measured' => $values === [] && $notMeasured];
        }

        $measured = array_filter($used, fn (stdClass $figure): bool => (float) $figure->denominator > 0.0);
        $numerator = (float) array_sum(array_map(fn (stdClass $figure): float => (float) $figure->numerator, $measured));
        $denominator = (float) array_sum(array_map(fn (stdClass $figure): float => (float) $figure->denominator, $measured));

        return [
            'value' => $denominator > 0.0 ? round($numerator / $denominator * ($measure === IndicatorMeasure::Percentage ? 100 : 1), 2) : null,
            'numerator' => $denominator > 0.0 ? $numerator : null,
            'denominator' => $denominator > 0.0 ? $denominator : null,
            'not_measured' => $measured === [] && $notMeasured,
        ];
    }

    /**
     * Fills each row's target, total, the ratios' excluded count and the
     * achieved percentage from the extraction (contracts §14.1): a center
     * row from centerTotals() (a shared target's whole value against that
     * center's own figure), a donor row from targetTotals().
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function withTotals(array $rows, bool $isDonorView): array
    {
        $extraction = $this->extraction();
        $totals = $isDonorView ? $extraction->targetTotals() : $extraction->centerTotals();

        foreach ($rows as $index => $row) {
            $figures = $isDonorView
                ? ($totals[$row['target_ids'][0]] ?? null)
                : ($totals[$row['place_key'].'|'.$row['library_id']] ?? null);

            if ($figures === null) {
                continue;
            }

            $rows[$index]['target'] = $figures['target'];
            $rows[$index]['total'] = $figures['total'];
            $rows[$index]['excluded'] = $figures['excluded'];
            $rows[$index]['achieved'] = $figures['achieved'];
        }

        return $rows;
    }
}
