<?php

use App\Enums\ReportStatus;
use App\Models\HrEmployee;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Strategy;
use App\Models\AnnualPlan;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('titles.annual_plans')] class extends Component {
    use WithPagination;

    /**
     * The widest grid the matrix will render.
     *
     * Columns are fiscal years, so an unbounded range would grow the row width
     * without limit as history accumulates. Ten keeps a full row readable on a
     * desktop screen; a wider span is clamped and the toolbar says so.
     */
    private const MAX_YEAR_COLUMNS = 10;

    /**
     * The fiscal years the grid shows as columns.
     *
     * A set rather than a range: a reader comparing 2023 against 2027 should
     * not have to carry the four years between them. Empty means "every year
     * that has a plan", which is the view the page opens on.
     *
     * @var array<int, int>
     */
    #[Url(except: [])]
    public array $years = [];

    #[Url(except: '')]
    public string $manager = '';

    /**
     * The program-name search narrowing the matrix's rows.
     */
    #[Url(as: 'q', except: '')]
    public string $programSearch = '';

    /**
     * The fiscal year the cockpit reads, or '' to let it pick one.
     */
    #[Url(except: '')]
    public string $focusYear = '';

    public bool $showPlanModal = false;

    public ?int $editingPlanId = null;

    public ?int $deletingPlanId = null;

    /**
     * Whether the delete confirmation is on screen.
     *
     * Carried separately from deletingPlanId because the modal has to be bound
     * with wire:model: an :open expression is only read when the dialog is
     * first rendered, so a Livewire patch that flips it never opens the dialog.
     */
    public bool $showDeleteModal = false;

    public string $planProgramId = '';

    public string $planManagerId = '';

    public string $planYear = '';

    public function updatedManager(): void
    {
        $this->resetPage();
    }

    /**
     * Add a fiscal year to the visible columns, or take it away again.
     *
     * Kept sorted so the grid's column order never depends on the order a
     * reader happened to tick the boxes in.
     */
    public function toggleYear(int $year): void
    {
        $years = in_array($year, $this->years, true)
            ? array_values(array_diff($this->years, [$year]))
            : [...$this->years, $year];

        sort($years);

        $this->years = $years;
        $this->resetPage();
    }

    public function clearYears(): void
    {
        $this->years = [];
        $this->resetPage();
    }

    public function updatedProgramSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Point the cockpit at one fiscal year without moving the grid.
     *
     * The year column and the cockpit read the same figure, so focusing a year
     * is a read, not a filter: the rest of the span stays on screen to compare
     * against.
     */
    public function focusOnYear(int $year): void
    {
        $this->focusYear = (string) $year;
    }

    public function clearFilters(): void
    {
        $this->years = [];
        $this->manager = '';
        $this->programSearch = '';
        $this->focusYear = '';
        $this->resetPage();
    }

    /**
     * Every fiscal year an annual plan has been written for, ascending.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function yearOptions(): array
    {
        return AnnualPlan::query()
            ->select('year')
            ->distinct()
            ->orderBy('year')
            ->pluck('year')
            ->map(fn ($year): int => (int) $year)
            ->all();
    }

    /**
     * The columns the grid would show if there were no cap.
     *
     * Split out so the toolbar can say whether the cap actually cut anything
     * without recomputing the rule that produced the columns.
     *
     * @return array<int, int>
     */
    private function candidateYearColumns(): array
    {
        if ($this->years === []) {
            $available = $this->yearOptions;

            return $available === []
                ? []
                : range($available[0], $available[count($available) - 1]);
        }

        $selected = array_values(array_unique(array_map('intval', $this->years)));
        sort($selected);

        return $selected;
    }

    /**
     * The fiscal years rendered as columns, gaps included.
     *
     * With no selection the grid spans every year from the earliest plan to the
     * latest, gaps included: a year nobody planned still earns a column,
     * because an empty column is the point of a coverage matrix.
     *
     * A selection shows exactly the years chosen and does not fill the holes
     * between them — a reader who asked for 2023 and 2027 is comparing two
     * years, not planning five.
     *
     * Either way the list is capped at MAX_YEAR_COLUMNS, keeping the most
     * recent years when it has to cut.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function yearColumns(): array
    {
        return array_slice($this->candidateYearColumns(), -self::MAX_YEAR_COLUMNS);
    }

    /**
     * How the visible columns should be described in the toolbar.
     *
     * A contiguous run reads as a span; anything else has to be listed, because
     * "2023–2027" would claim three columns that are not on screen.
     *
     * @return array{from: int, to: int, contiguous: bool, clamped: bool, list: string}|null
     */
    #[Computed]
    public function yearRange(): ?array
    {
        $columns = $this->yearColumns;

        if ($columns === []) {
            return null;
        }

        $from = $columns[0];
        $to = $columns[count($columns) - 1];
        $requested = count($this->candidateYearColumns());

        return [
            'from' => $from,
            'to' => $to,
            'contiguous' => ($to - $from + 1) === count($columns),
            'clamped' => $requested > self::MAX_YEAR_COLUMNS,
            'list' => implode(', ', $columns),
        ];
    }

    /**
     * The fiscal year the cockpit reads.
     *
     * A focus year outside the visible span is ignored rather than corrected,
     * so narrowing the range never leaves the cockpit reporting on a column
     * nobody can see. Without a usable choice it lands on this calendar year,
     * or on the most recent column when this year is not in the span.
     */
    #[Computed]
    public function activeYear(): ?int
    {
        $columns = $this->yearColumns;

        if ($columns === []) {
            return null;
        }

        if ($this->focusYear !== '' && in_array((int) $this->focusYear, $columns, true)) {
            return (int) $this->focusYear;
        }

        $thisYear = (int) now()->year;

        return in_array($thisYear, $columns, true) ? $thisYear : $columns[count($columns) - 1];
    }

    /**
     * The managers accountable for at least one annual plan.
     *
     * Users without a plan are left out, so the filter cannot be set to a value
     * that empties the grid.
     *
     * @return Collection<int, HrEmployee>
     */
    #[Computed]
    public function managerOptions(): Collection
    {
        // Employees live in the HR database, so "has a plan" is resolved to
        // ids first instead of a whereHas() across connections.
        return HrEmployee::query()
            ->select(HrEmployee::NameColumns)
            ->whereIn('id', AnnualPlan::query()->distinct()->pluck('manager_id'))
            ->orderBy(\App\Enums\Locale::current() === \App\Enums\Locale::Arabic ? 'first_name_ar' : 'first_name_en')
            ->orderBy(\App\Enums\Locale::current() === \App\Enums\Locale::Arabic ? 'last_name_ar' : 'last_name_en')
            ->orderBy('id')
            ->get();
    }

    /**
     * The programs forming the rows of the matrix.
     *
     * Without a manager filter every program is listed, including ones with no
     * plan at all: an entirely empty row is how the matrix reports a program
     * nobody has planned. With a manager filter, a program earns its row only
     * by having a plan that manager owns inside the visible fiscal years.
     *
     * @return LengthAwarePaginator<int, Program>
     */
    #[Computed]
    public function programRows(): LengthAwarePaginator
    {
        $columns = $this->yearColumns;
        $managerId = $this->selectedManagerId();

        return Program::query()
            ->select(['id', 'strategy_id'])
            ->with(['strategy:id,name,start_year,end_year'])
            ->when($managerId !== null, fn (Builder $query) => $query->whereHas(
                'annualPlans',
                function (Builder $query) use ($managerId, $columns): void {
                    $query->managedBy($managerId);

                    if ($columns !== []) {
                        $query->inYears($columns);
                    }
                },
            ))
            ->when($this->programSearch !== '', fn (Builder $query) => $query->whereNameLike($this->programSearch))
            ->orderByName()
            ->paginate(15);
    }

    /**
     * The plans of the visible rows, keyed "programId:year".
     *
     * One query fills the whole grid. Every cell is then an array lookup, so
     * the number of queries does not move as the matrix grows.
     *
     * @return array<string, AnnualPlan>
     */
    #[Computed]
    public function planCells(): array
    {
        $columns = $this->yearColumns;
        $programIds = $this->programRows->getCollection()->modelKeys();

        if ($columns === [] || $programIds === []) {
            return [];
        }

        return AnnualPlan::query()
            ->select(['id', 'slug', 'program_id', 'manager_id', 'year'])
            ->whereIn('program_id', $programIds)
            ->inYears($columns)
            ->managedBy($this->selectedManagerId())
            ->with('manager:id,first_name_en,last_name_en,first_name_ar,last_name_ar')
            ->withCount('planIndicators')
            ->get()
            ->keyBy(fn (AnnualPlan $plan): string => $plan->program_id.':'.$plan->year)
            ->all();
    }

    /**
     * Delivery signal for every plan on screen, keyed by plan id.
     *
     * Read from plan_indicators rather than annual_plans, so filling the cells
     * with progress costs one query for the whole grid and leaves the matrix's
     * four reads of annual_plans untouched. Reports fan out against targets,
     * hence the distinct counts.
     *
     * @return array<int, array{targets: int, attributed: int, reports: int, approved: int}>
     */
    #[Computed]
    public function planDelivery(): array
    {
        $planIds = array_map(fn (AnnualPlan $plan): int => $plan->id, $this->planCells);

        if ($planIds === []) {
            return [];
        }

        return PlanIndicator::query()
            ->where('plan_indicators.source_type', 'annual_plan')
            ->whereIn('plan_indicators.source_id', $planIds)
            ->leftJoin('reports', 'reports.plan_indicator_id', '=', 'plan_indicators.id')
            ->groupBy('plan_indicators.source_id')
            ->selectRaw('plan_indicators.source_id as plan_id')
            ->selectRaw('count(distinct plan_indicators.id) as targets')
            ->selectRaw('count(distinct case when plan_indicators.project_id is not null then plan_indicators.id end) as attributed')
            ->selectRaw('count(distinct reports.id) as reports')
            ->selectRaw('count(distinct case when reports.status = ? then reports.id end) as approved', [ReportStatus::Approved->value])
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->plan_id => [
                'targets' => (int) $row->targets,
                'attributed' => (int) $row->attributed,
                'reports' => (int) $row->reports,
                'approved' => (int) $row->approved,
            ]])
            ->all();
    }

    /**
     * What each visible fiscal year holds across the whole filtered set.
     *
     * Counted in the database rather than from the cells, so the figures cover
     * every page of programs and not just the one on screen. The target and
     * report totals ride along on the same grouped read that already counts the
     * plans, which is why the column footers can carry delivery signal without
     * adding a query.
     *
     * @return array<int, array{plans: int, targets: int, reports: int, approved: int, managers: int}>
     */
    #[Computed]
    public function yearStats(): array
    {
        $columns = $this->yearColumns;

        if ($columns === []) {
            return [];
        }

        return AnnualPlan::query()
            ->leftJoin('plan_indicators', fn (JoinClause $join) => $join
                ->on('plan_indicators.source_id', '=', 'annual_plans.id')
                ->where('plan_indicators.source_type', '=', 'annual_plan'))
            ->leftJoin('reports', 'reports.plan_indicator_id', '=', 'plan_indicators.id')
            ->inYears($columns)
            ->managedBy($this->selectedManagerId())
            ->groupBy('annual_plans.year')
            ->selectRaw('annual_plans.year as year')
            ->selectRaw('count(distinct annual_plans.id) as plans')
            ->selectRaw('count(distinct annual_plans.manager_id) as managers')
            ->selectRaw('count(distinct plan_indicators.id) as targets')
            ->selectRaw('count(distinct reports.id) as reports')
            ->selectRaw('count(distinct case when reports.status = ? then reports.id end) as approved', [ReportStatus::Approved->value])
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->year => [
                'plans' => (int) $row->plans,
                'targets' => (int) $row->targets,
                'reports' => (int) $row->reports,
                'approved' => (int) $row->approved,
                'managers' => (int) $row->managers,
            ]])
            ->all();
    }

    /**
     * The figures the year rail draws, one entry per visible column.
     *
     * Coverage is plans over the programs in scope, so a bar reads as "how much
     * of the portfolio was planned that year". Heights are relative to the
     * busiest column rather than absolute, because the comparison the manager
     * makes here is between years, not against a ceiling.
     *
     * @return array<int, array{year: int, plans: int, targets: int, reports: int, approved: int, managers: int, coverage: int, planShare: int, targetShare: int, isCurrent: bool, isActive: bool}>
     */
    #[Computed]
    public function yearRail(): array
    {
        $stats = $this->yearStats;
        $programTotal = $this->programRows->total();
        $peakPlans = max(1, (int) collect($stats)->max('plans'));
        $peakTargets = max(1, (int) collect($stats)->max('targets'));
        $thisYear = (int) now()->year;

        return collect($this->yearColumns)
            ->map(function (int $year) use ($stats, $programTotal, $peakPlans, $peakTargets, $thisYear): array {
                $entry = $stats[$year] ?? ['plans' => 0, 'targets' => 0, 'reports' => 0, 'approved' => 0, 'managers' => 0];

                return [
                    'year' => $year,
                    ...$entry,
                    'coverage' => $programTotal > 0 ? (int) round($entry['plans'] / $programTotal * 100) : 0,
                    'planShare' => (int) round($entry['plans'] / $peakPlans * 100),
                    'targetShare' => (int) round($entry['targets'] / $peakTargets * 100),
                    'isCurrent' => $year === $thisYear,
                    'isActive' => $year === $this->activeYear,
                ];
            })
            ->all();
    }

    /**
     * The focused year's headline figures.
     *
     * @return array{year: int, plans: int, targets: int, reports: int, approved: int, managers: int, coverage: int, gaps: int, reportShare: int, programs: int, targetsDelta: int|null, plansDelta: int|null}|null
     */
    #[Computed]
    public function cockpit(): ?array
    {
        $year = $this->activeYear;

        if ($year === null) {
            return null;
        }

        $stats = $this->yearStats;
        $entry = $stats[$year] ?? ['plans' => 0, 'targets' => 0, 'reports' => 0, 'approved' => 0, 'managers' => 0];
        $previous = $stats[$year - 1] ?? null;
        $programTotal = $this->programRows->total();

        return [
            'year' => $year,
            ...$entry,
            'programs' => $programTotal,
            'coverage' => $programTotal > 0 ? (int) round($entry['plans'] / $programTotal * 100) : 0,
            'gaps' => max(0, $programTotal - $entry['plans']),
            'reportShare' => $entry['reports'] > 0 ? (int) round($entry['approved'] / $entry['reports'] * 100) : 0,
            'plansDelta' => $previous === null ? null : $entry['plans'] - $previous['plans'],
            'targetsDelta' => $previous === null ? null : $entry['targets'] - $previous['targets'],
        ];
    }

    /**
     * Every program the plan form can be written against.
     *
     * The full list, not the filtered rows: a plan is created for a program
     * whether or not the current filters put it on screen.
     *
     * @return Collection<int, Program>
     */
    #[Computed]
    public function programOptions(): Collection
    {
        return Program::options();
    }

    /**
     * Every HR employee the plan can be made accountable to.
     *
     * managerOptions() is the grid's filter and lists only employees who
     * already own a plan; the form must reach the ones who do not yet.
     *
     * @return Collection<int, HrEmployee>
     */
    #[Computed]
    public function employeeOptions(): Collection
    {
        return HrEmployee::pickerOptions();
    }

    /**
     * The fiscal years the form offers.
     *
     * Built from the years already planned, plus this year and the next, so a
     * new fiscal year can be opened without a data change. The year being
     * edited — or the one carried in from a clicked cell — is folded in as
     * well, so a plan written for an older year stays selectable in its own
     * form. Reads the memoised yearOptions rather than the table, which is why
     * the form costs the matrix no extra read of annual_plans.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function yearFormOptions(): array
    {
        $thisYear = (int) now()->year;

        $years = [...$this->yearOptions, $thisYear, $thisYear + 1];

        if ($this->planYear !== '') {
            $years[] = (int) $this->planYear;
        }

        $years = array_values(array_unique($years));
        sort($years);

        return $years;
    }

    /**
     * What the delete confirmation has to warn about.
     *
     * The plan's observer removes the targets committed in it, and the reports
     * filed against those targets go with them, so the count is named in the
     * dialog rather than discovered afterwards. Null while no delete is
     * pending, which keeps this read out of the matrix's query budget.
     *
     * @return array{plan: AnnualPlan, targets: int}|null
     */
    #[Computed]
    public function deletingPlan(): ?array
    {
        if ($this->deletingPlanId === null) {
            return null;
        }

        $plan = AnnualPlan::query()
            ->select(['id', 'program_id', 'manager_id', 'year'])
            ->with('program:id')
            ->withCount('planIndicators')
            ->find($this->deletingPlanId);

        if ($plan === null) {
            return null;
        }

        return ['plan' => $plan, 'targets' => $plan->plan_indicators_count];
    }

    /**
     * Open the form for a new plan.
     *
     * A gap in the grid passes its own program and fiscal year, so clicking an
     * empty cell writes the plan that cell is missing instead of asking for
     * coordinates the user already pointed at.
     */
    public function openCreateModal(?int $programId = null, ?int $year = null): void
    {
        $this->resetPlanForm();
        $this->planProgramId = $programId === null ? '' : (string) $programId;
        $this->planYear = $year === null ? '' : (string) $year;
        $this->showPlanModal = true;
    }

    public function openEditModal(int $planId): void
    {
        $plan = AnnualPlan::query()
            ->select(['id', 'program_id', 'manager_id', 'year'])
            ->findOrFail($planId);

        $this->resetValidation();
        $this->editingPlanId = $plan->id;
        $this->planProgramId = (string) $plan->program_id;
        $this->planManagerId = (string) $plan->manager_id;
        $this->planYear = (string) $plan->year;
        $this->showPlanModal = true;
    }

    /**
     * Write the plan.
     *
     * The (program_id, year) pair is unique in the database, so the pair is
     * validated here too: without that rule a second plan for the same year
     * surfaces as a driver error instead of a message on the field.
     */
    public function savePlan(): void
    {
        $validated = $this->validate([
            'planProgramId' => ['required', 'integer', Rule::exists(Program::class, 'id')],
            'planManagerId' => ['required', 'integer', Rule::exists(HrEmployee::class, 'id')],
            'planYear' => [
                'required',
                'integer',
                'min:2000',
                'max:2100',
                Rule::unique(AnnualPlan::class, 'year')
                    ->where(fn ($query) => $query->where('program_id', (int) $this->planProgramId))
                    ->ignore($this->editingPlanId),
            ],
        ], [
            'planProgramId.required' => __('annual_plans.select_the_program_this_plan_covers'),
            'planProgramId.exists' => __('annual_plans.the_selected_program_is_no_longer_available'),
            'planManagerId.required' => __('annual_plans.select_the_manager_accountable_for_this_plan'),
            'planManagerId.exists' => __('annual_plans.the_selected_manager_is_no_longer_available'),
            'planYear.required' => __('annual_plans.select_the_fiscal_year_this_plan_covers'),
            'planYear.unique' => __('annual_plans.this_program_already_has_a_plan_for_that_fiscal_year'),
        ]);

        $plan = $this->editingPlanId === null
            ? new AnnualPlan
            : AnnualPlan::query()->findOrFail($this->editingPlanId);

        $plan->fill([
            'program_id' => (int) $validated['planProgramId'],
            'manager_id' => (int) $validated['planManagerId'],
            'year' => (int) $validated['planYear'],
        ])->save();

        $wasRecentlyCreated = $plan->wasRecentlyCreated;

        // Read against the span the writer was looking at, before the write
        // drops the memoised columns.
        $landedOutsideTheVisibleSpan = ! in_array($plan->year, $this->yearColumns, true);

        $this->showPlanModal = false;
        $this->resetPlanForm();

        // A plan written for a year the filters exclude would be saved into an
        // empty screen: the toast would report success and nothing would
        // appear. The window opens to show the work instead.
        if ($landedOutsideTheVisibleSpan) {
            $this->years = [];
            $this->focusYear = (string) $plan->year;
        }

        $this->refreshMatrix();

        Flux::toast(
            variant: 'success',
            text: $wasRecentlyCreated ? __('annual_plans.annual_plan_created') : __('annual_plans.annual_plan_updated'),
        );
    }

    public function confirmDelete(int $planId): void
    {
        $this->deletingPlanId = $planId;
        $this->showDeleteModal = true;
    }

    public function cancelDelete(): void
    {
        $this->deletingPlanId = null;
        $this->showDeleteModal = false;
    }

    /**
     * Delete the plan and everything committed inside it.
     *
     * AnnualPlan::delete() brackets the observer's removal of the plan's
     * targets in a transaction, so a failure partway through cannot leave the
     * targets gone and the plan standing.
     */
    public function deletePlan(): void
    {
        if ($this->deletingPlanId === null) {
            return;
        }

        AnnualPlan::query()->findOrFail($this->deletingPlanId)->delete();

        $this->deletingPlanId = null;
        $this->showDeleteModal = false;
        $this->refreshMatrix();

        Flux::toast(variant: 'success', text: __('annual_plans.annual_plan_deleted'));
    }

    public function closePlanModal(): void
    {
        $this->showPlanModal = false;
        $this->resetPlanForm();
    }

    /**
     * Drop every memoised read of the grid after a write.
     *
     * The cells, the column totals and the year list are all computed
     * properties resolved earlier in the same request, so a write that is not
     * followed by this leaves the matrix rendering the state it had before.
     *
     * The page is deliberately left alone. Rows are programs, and a write
     * touches a cell rather than the row set, so resetting to page one would
     * throw a reader editing the twentieth program back to the first for no
     * gain.
     */
    protected function refreshMatrix(): void
    {
        unset(
            $this->yearOptions,
            $this->yearRange,
            $this->yearColumns,
            $this->activeYear,
            $this->managerOptions,
            $this->programRows,
            $this->planCells,
            $this->planDelivery,
            $this->yearStats,
            $this->yearRail,
            $this->cockpit,
            $this->yearFormOptions,
            $this->deletingPlan,
        );
    }

    protected function resetPlanForm(): void
    {
        $this->resetValidation();
        $this->editingPlanId = null;
        $this->planProgramId = '';
        $this->planManagerId = '';
        $this->planYear = '';
    }

    /**
     * The plan of one program in one fiscal year, or null where none exists.
     */
    public function planFor(int $programId, int $year): ?AnnualPlan
    {
        return $this->planCells[$programId.':'.$year] ?? null;
    }

    /**
     * The manager the grid is filtered to, or null for all of them.
     */
    private function selectedManagerId(): ?int
    {
        return $this->manager === '' ? null : (int) $this->manager;
    }
};
?>

@php
    $secondaryButtonClasses = 'border-secondary-light! bg-white! text-secondary-dark! hover:bg-secondary-soft! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white! dark:hover:bg-secondary-medium!';

    $yearColumns = $this->yearColumns;
    $yearRange = $this->yearRange;
    $yearStats = $this->yearStats;
    $yearRail = $this->yearRail;
    $cockpit = $this->cockpit;
    $delivery = $this->planDelivery;
    $activeYear = $this->activeYear;
    $filtersAreActive = $years !== [] || $manager !== '' || $programSearch !== '';
@endphp

<section
    class="admin-directory @container/matrix flex min-h-full w-full flex-1 flex-col gap-8 bg-white px-1 text-secondary-dark dark:bg-secondary-dark dark:text-white"
    aria-labelledby="annual-plan-matrix-heading"
    x-data="{ hoverYear: null, hoverProgram: null, gapsOnly: false }"
>
    <x-ui.page-header heading-id="annual-plan-matrix-heading" :title="__('common.annual_plans')" :count="array_sum(array_column($yearStats, 'plans'))">
        <x-slot:filters>
            <x-ui.input
                type="search"
                icon="magnifying-glass"
                :label="__('annual_plans.search_programs')"
                :placeholder="__('annual_plans.search_programs_placeholder')"
                wire:model.live.debounce.300ms="programSearch"
                clearable
            />

            <x-ui.header-filter-multiselect
                id="annual-plan-years"
                :label="__('annual_plans.fiscal_year')"
                :empty-label="__('annual_plans.all_fiscal_years')"
                :selected="$years"
                toggle="toggleYear"
                clear="clearYears"
            >
                @foreach ($this->yearOptions as $yearOption)
                    <flux:select.option :value="$yearOption" :wire:key="'year-option-'.$yearOption">{{ $yearOption }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-multiselect>

            <x-ui.header-filter-select id="annual-plan-manager" :label="__('annual_plans.assigned_manager')" wire:model.live="manager" :value="$manager">
                <flux:select.option value="">{{ __('common.all_managers') }}</flux:select.option>
                @foreach ($this->managerOptions as $managerOption)
                    <flux:select.option :value="$managerOption->id" :wire:key="'manager-filter-'.$managerOption->id">
                        {{ $managerOption->name }} · {{ $managerOption->secondary_name }}
                    </flux:select.option>
                @endforeach
            </x-ui.header-filter-select>
        </x-slot:filters>

        <x-slot:actions>
            {{-- Held at 44px, not the usual 40: this button shares a row with the filter controls, which are 2.75rem, and a 4px difference reads as a misalignment. --}}
            <x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation" wire:click="openCreateModal" data-test="create-annual-plan-button">
                {{ __('annual_plans.new_annual_plan') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="flex justify-end">
        <span wire:loading.delay.flex wire:target="toggleYear,clearYears,manager,programSearch,clearFilters,focusOnYear" class="hidden items-center gap-1.5 text-xs text-secondary-medium dark:text-secondary-soft">
            <flux:icon.arrow-path class="size-3.5 animate-spin" /> {{ __('common.updating') }}
        </span>
        @if ($filtersAreActive)
            <flux:button size="sm" variant="ghost" wire:click="clearFilters">{{ __('common.reset_filters') }}</flux:button>
        @endif
    </div>

    @if ($yearColumns === [])
        <x-ui.empty-state
            icon="calendar-days"
            :heading="__('common.no_annual_plans_yet')"
            :body="__('annual_plans.once_a_program_has_an_annual_plan_its_fiscal_years_appear_he')"
            class="rounded-xl border border-secondary-soft dark:border-secondary-medium"
        >
            <x-slot:action>
                <x-ui.button size="sm" tone="primary" icon="plus" wire:click="openCreateModal">{{ __('annual_plans.new_annual_plan') }}</x-ui.button>
            </x-slot:action>
        </x-ui.empty-state>
    @else
        @php
            // The arc is plotted in its own coordinate space; every marker,
            // value label and year button is then placed with the same
            // percentages, so the curve and the HTML overlaid on top of it
            // never drift apart however the panel is stretched.
            $arcViewW = 720;
            $arcViewH = 150;
            // The tallest point still stops short of the top edge, leaving
            // room for its value label to sit above the dot without
            // colliding with the legend row.
            $arcPlotTop = $arcViewH * 0.24;
            $arcCount = count($yearRail);
            $arcPadX = $arcCount > 1 ? $arcViewW * 0.045 : 0;

            $arcPoints = collect($yearRail)->values()->map(function (array $rail, int $i) use ($arcCount, $arcViewW, $arcViewH, $arcPadX, $arcPlotTop): array {
                $x = $arcCount > 1
                    ? $arcPadX + $i * (($arcViewW - 2 * $arcPadX) / ($arcCount - 1))
                    : $arcViewW / 2;

                $planY = $arcPlotTop + (1 - max(4, $rail['planShare']) / 100) * ($arcViewH - $arcPlotTop);
                $targetY = $arcPlotTop + (1 - max(2, $rail['targetShare']) / 100) * ($arcViewH - $arcPlotTop);

                return [
                    'rail' => $rail,
                    'x' => $x,
                    'xPct' => $arcViewW > 0 ? round($x / $arcViewW * 100, 3) : 0,
                    'planY' => $planY,
                    'planYPct' => $arcViewH > 0 ? round($planY / $arcViewH * 100, 3) : 0,
                    'targetY' => $targetY,
                ];
            })->all();

            /**
             * A smoothed path through a series of points.
             *
             * Each segment eases through its own midpoint rather than joining
             * points with straight lines, so the arc reads as one continuous
             * curve instead of a jagged plot.
             */
            $buildSmoothPath = function (array $points, string $yKey): string {
                if ($points === []) {
                    return '';
                }

                $path = sprintf('M%.2F,%.2F', $points[0]['x'], $points[0][$yKey]);

                for ($i = 1; $i < count($points); $i++) {
                    $prev = $points[$i - 1];
                    $curr = $points[$i];
                    $midX = ($prev['x'] + $curr['x']) / 2;

                    $path .= sprintf(' C%.2F,%.2F %.2F,%.2F %.2F,%.2F', $midX, $prev[$yKey], $midX, $curr[$yKey], $curr['x'], $curr[$yKey]);
                }

                return $path;
            };

            $arcPlanLine = $buildSmoothPath($arcPoints, 'planY');
            $arcTargetLine = $buildSmoothPath($arcPoints, 'targetY');

            $arcFirstPoint = $arcPoints[0] ?? null;
            $arcLastPoint = $arcPoints === [] ? null : end($arcPoints);
            $arcPlanArea = $arcFirstPoint === null ? '' : $arcPlanLine
                .sprintf(' L%.2F,%.2F', $arcLastPoint['x'], $arcViewH)
                .sprintf(' L%.2F,%.2F Z', $arcFirstPoint['x'], $arcViewH);

            $gaugeRadius = 46;
            $gaugeCircumference = 2 * M_PI * $gaugeRadius;
            $gaugeShare = $cockpit === null ? 0 : min(100, max(0, $cockpit['coverage']));
            $gaugeOffset = $gaugeCircumference * (1 - $gaugeShare / 100);
        @endphp

        {{-- Layer one: the planning arc, and the year it points the cockpit at. --}}
        <section class="overflow-hidden rounded-xl border border-board-line bg-board" aria-labelledby="planning-arc-heading">
            <div class="flex flex-wrap items-end justify-between gap-3 border-b border-board-line bg-board-head px-5 py-4">
                <div class="min-w-0">
                    <h2 id="planning-arc-heading" class="text-base font-bold">{{ __('annual_plans.planning_arc') }}</h2>
                    <p class="mt-0.5 type-meta text-secondary-medium dark:text-secondary-soft">{{ __('annual_plans.planning_arc_hint') }}</p>
                </div>
                <p class="type-meta text-secondary-medium tabular-nums dark:text-secondary-soft">
                    @if ($yearRange !== null)
                        {{-- A gappy selection cannot be described as a span: "2023–2027" would claim columns that are not on screen. --}}
                        @if ($yearRange['contiguous'])
                            {{ __('annual_plans.fiscal_years_from_to', ['from' => $yearRange['from'], 'to' => $yearRange['to']]) }}
                        @else
                            {{ __('annual_plans.fiscal_years_list', ['years' => $yearRange['list']]) }}
                        @endif
                        @if ($yearRange['clamped'])
                            <span class="font-normal">· {{ __('annual_plans.narrowed_to_count_columns', ['count' => count($yearColumns)]) }}</span>
                        @endif
                    @else
                        {{ __('annual_plans.no_fiscal_years_to_show') }}
                    @endif
                </p>
            </div>

            <div class="grid gap-0 @4xl/matrix:grid-cols-[minmax(0,1fr)_22rem]">
                <div class="px-5 pt-6 pb-5 @4xl/matrix:border-e @4xl/matrix:border-board-line" dir="ltr">
                    <div class="mb-4 flex flex-wrap items-center gap-4 type-meta text-secondary-medium dark:text-secondary-soft">
                        <span class="inline-flex items-center gap-2">
                            <span aria-hidden="true" class="h-0.5 w-5 rounded-full bg-primary"></span>
                            {{ __('annual_plans.chart_series_plans') }}
                        </span>
                        <span class="inline-flex items-center gap-2">
                            <span aria-hidden="true" class="h-0 w-5 border-t-2 border-dashed border-secondary-medium dark:border-secondary-soft"></span>
                            {{ __('annual_plans.chart_series_targets') }}
                        </span>
                    </div>

                    {{-- The arc itself: decorative and aria-hidden, since every data point is restated by a focusable, labelled year button below it. --}}
                    <div class="relative" style="aspect-ratio: {{ $arcViewW }} / {{ $arcViewH }}" aria-hidden="true">
                        <svg class="size-full overflow-visible" viewBox="0 0 {{ $arcViewW }} {{ $arcViewH }}">
                            <path d="{{ $arcPlanArea }}" class="fill-secondary-soft dark:fill-secondary-medium" />

                            <path
                                d="{{ $arcTargetLine }}"
                                class="fill-none stroke-secondary-medium dark:stroke-secondary-soft"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-dasharray="1 8"
                            />

                            <path
                                d="{{ $arcPlanLine }}"
                                class="arc-line-draw fill-none stroke-primary"
                                style="--arc-length: 1600"
                                stroke-width="3"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />

                            @foreach ($arcPoints as $point)
                                <circle cx="{{ $point['x'] }}" cy="{{ $point['targetY'] }}" r="2.5" class="fill-secondary-medium dark:fill-secondary-soft" />
                            @endforeach

                            @foreach ($arcPoints as $point)
                                @if ($point['rail']['isActive'])
                                    <circle cx="{{ $point['x'] }}" cy="{{ $point['planY'] }}" r="9" class="fill-none stroke-primary" stroke-width="1.5" />
                                @endif
                                <circle
                                    cx="{{ $point['x'] }}" cy="{{ $point['planY'] }}"
                                    r="{{ $point['rail']['isActive'] ? 5.5 : 4 }}"
                                    stroke-width="2"
                                    @class([
                                        'fill-primary stroke-primary' => $point['rail']['isActive'],
                                        'fill-board stroke-primary' => ! $point['rail']['isActive'],
                                    ])
                                />
                            @endforeach
                        </svg>

                        <div class="pointer-events-none absolute inset-0">
                            @foreach ($arcPoints as $point)
                                <span
                                    class="absolute -translate-x-1/2 -translate-y-[calc(100%+10px)] text-sm font-bold tabular-nums text-secondary-dark dark:text-white"
                                    style="left: {{ $point['xPct'] }}%; top: {{ $point['planYPct'] }}%;"
                                >{{ $point['rail']['plans'] }}</span>
                            @endforeach
                        </div>
                    </div>

                    {{-- The year axis: the real controls, restated in full for assistive tech via each button's label. --}}
                    <div class="relative mt-2 h-16">
                        @foreach ($arcPoints as $point)
                            @php($rail = $point['rail'])
                            <div class="absolute top-0 flex -translate-x-1/2 flex-col items-center gap-1.5" style="left: {{ $point['xPct'] }}%" wire:key="rail-{{ $rail['year'] }}">
                                <button
                                    type="button"
                                    wire:click="focusOnYear({{ $rail['year'] }})"
                                    aria-pressed="{{ $rail['isActive'] ? 'true' : 'false' }}"
                                    aria-label="{{ __('annual_plans.focus_year_plans_targets', ['year' => $rail['year'], 'plans' => $rail['plans'], 'targets' => $rail['targets']]) }}"
                                    @class([
                                        'min-h-9 cursor-pointer rounded-md px-2.5 py-1 text-sm font-bold tabular-nums transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                                        'bg-primary text-white' => $rail['isActive'],
                                        'text-secondary-dark hover:bg-secondary-soft dark:text-white dark:hover:bg-secondary-medium' => ! $rail['isActive'],
                                    ])
                                >{{ $rail['year'] }}</button>

                                <span @class([
                                    'type-label rounded-full bg-primary px-2 py-0.5 text-white',
                                    'invisible' => ! $rail['isCurrent'],
                                ])>{{ __('annual_plans.now') }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>

                @if ($cockpit !== null)
                    <aside class="border-t border-board-line bg-board-head px-5 py-5 @4xl/matrix:border-t-0" aria-labelledby="cockpit-heading">
                        <span id="cockpit-heading" class="sr-only">{{ __('annual_plans.focused_fiscal_year') }}</span>

                        <div class="flex items-center gap-5">
                            <div class="relative size-24 shrink-0" role="img" aria-label="{{ __('annual_plans.portfolio_covered') }}: {{ $gaugeShare }}%">
                                <svg viewBox="0 0 120 120" class="size-full -rotate-90" aria-hidden="true">
                                    <circle cx="60" cy="60" r="{{ $gaugeRadius }}" class="fill-none stroke-board-line" stroke-width="10" />
                                    <circle
                                        cx="60" cy="60" r="{{ $gaugeRadius }}"
                                        class="fill-none stroke-primary transition-[stroke-dashoffset] duration-500 ease-out motion-reduce:transition-none"
                                        stroke-width="10"
                                        stroke-linecap="round"
                                        stroke-dasharray="{{ $gaugeCircumference }}"
                                        stroke-dashoffset="{{ $gaugeOffset }}"
                                    />
                                </svg>
                                <div class="absolute inset-0 flex items-center justify-center">
                                    <span class="text-xl font-bold tabular-nums text-secondary-dark dark:text-white" aria-hidden="true">{{ $gaugeShare }}%</span>
                                </div>
                            </div>

                            <div class="min-w-0">
                                <div class="flex items-baseline gap-2">
                                    <h3 class="text-3xl font-bold tracking-tight tabular-nums" aria-hidden="true">{{ $cockpit['year'] }}</h3>
                                    @if ($cockpit['plansDelta'] !== null && $cockpit['plansDelta'] !== 0)
                                        <span @class([
                                            'type-meta font-semibold tabular-nums',
                                            'text-status-good' => $cockpit['plansDelta'] > 0,
                                            'text-secondary-medium dark:text-secondary-soft' => $cockpit['plansDelta'] < 0,
                                        ])>{{ $cockpit['plansDelta'] > 0 ? '+' : '' }}{{ $cockpit['plansDelta'] }} {{ __('annual_plans.vs_previous_year') }}</span>
                                    @endif
                                </div>
                                <p class="type-label text-secondary-medium dark:text-secondary-soft">{{ __('annual_plans.portfolio_covered') }}</p>
                                <p class="mt-1.5 type-meta text-secondary-medium tabular-nums dark:text-secondary-soft">
                                    {{ __('annual_plans.coverage_with_gaps', ['percent' => $gaugeShare, 'gaps' => $cockpit['gaps']]) }}
                                </p>
                            </div>
                        </div>

                        <dl class="mt-5 grid grid-cols-3 divide-x divide-board-line overflow-hidden rounded-md border border-board-line bg-board rtl:divide-x-reverse">
                            <div class="px-3 py-2.5">
                                <dt class="type-label text-secondary-medium dark:text-secondary-soft">{{ __('common.targets') }}</dt>
                                <dd class="mt-0.5 text-xl font-bold tabular-nums">{{ $cockpit['targets'] }}</dd>
                            </div>
                            <div class="px-3 py-2.5">
                                <dt class="type-label text-secondary-medium dark:text-secondary-soft">{{ __('annual_plans.approved') }}</dt>
                                <dd class="mt-0.5 flex items-baseline gap-1 tabular-nums"><span class="text-xl font-bold">{{ $cockpit['approved'] }}</span><span class="text-sm text-secondary-medium dark:text-secondary-soft">/ {{ $cockpit['reports'] }}</span></dd>
                            </div>
                            <div class="px-3 py-2.5">
                                <dt class="type-label text-secondary-medium dark:text-secondary-soft">{{ __('annual_plans.managers') }}</dt>
                                <dd class="mt-0.5 text-xl font-bold tabular-nums">{{ $cockpit['managers'] }}</dd>
                            </div>
                        </dl>
                    </aside>
                @endif
            </div>
        </section>

        {{-- Layer two: the coverage matrix itself. --}}
        <section class="overflow-hidden rounded-xl border border-board-line bg-board" aria-labelledby="coverage-matrix-heading">
            <div class="flex min-h-12 flex-wrap items-center justify-between gap-3 border-b border-board-line bg-board-head px-5 py-3">
                <div class="min-w-0">
                    <h2 id="coverage-matrix-heading" class="text-base font-bold">{{ __('annual_plans.coverage_matrix') }}</h2>
                    <p class="mt-0.5 type-meta text-secondary-medium dark:text-secondary-soft">{{ __('annual_plans.coverage_matrix_hint') }}</p>
                </div>

                @if ($activeYear !== null)
                    <button
                        type="button"
                        x-on:click="gapsOnly = ! gapsOnly"
                        x-bind:aria-pressed="gapsOnly ? 'true' : 'false'"
                        class="inline-flex min-h-9 shrink-0 cursor-pointer items-center gap-1.5 rounded-md border px-2.5 type-meta font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                        x-bind:class="gapsOnly ? 'border-primary bg-primary text-white' : 'border-board-line text-secondary-medium hover:text-secondary-dark dark:text-secondary-soft dark:hover:text-white'"
                    >
                        <flux:icon.funnel variant="micro" class="size-3.5" aria-hidden="true" />
                        {{ __('annual_plans.gaps_in_year', ['year' => $activeYear]) }}
                    </button>
                @endif
            </div>
            {{-- How to read a cell, stated before the grid rather than after it. --}}
            <div class="flex flex-wrap items-center gap-x-5 gap-y-2 border-b border-board-line bg-board-head px-5 py-2.5 type-meta text-secondary-medium dark:text-secondary-soft">
                <span class="inline-flex items-center gap-1.5">
                    <span aria-hidden="true" class="size-3 shrink-0 rounded-[3px] border border-secondary-light bg-board"></span>
                    {{ __('annual_plans.legend_plan_on_file') }}
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <span aria-hidden="true" class="size-3 shrink-0 rounded-[3px] border border-dashed border-secondary-light"></span>
                    {{ __('annual_plans.legend_no_plan') }}
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <span aria-hidden="true" class="relative flex size-3 shrink-0 items-center justify-center rounded-[3px] border border-secondary-light bg-board">
                        <span class="absolute end-0 top-0 size-1.5 -translate-y-1/3 translate-x-1/3 rounded-full bg-primary rtl:translate-x-[-33%]"></span>
                    </span>
                    {{ __('annual_plans.legend_no_targets') }}
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <span aria-hidden="true" class="h-1.5 w-6 shrink-0 overflow-hidden rounded-full bg-secondary-soft dark:bg-secondary-medium"><span class="block h-full w-2/3 rounded-full bg-status-good"></span></span>
                    {{ __('annual_plans.legend_reporting') }}
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <span aria-hidden="true" class="size-3 shrink-0 rounded-[3px] border-2 border-primary bg-board"></span>
                    {{ __('annual_plans.legend_focused_year') }}
                </span>
            </div>

            @if ($this->programRows->isEmpty())
                <x-ui.empty-state
                    icon="calendar-days"
                    :heading="$filtersAreActive ? __('annual_plans.no_matching_annual_plans') : __('common.no_programs_yet')"
                    :body="$filtersAreActive ? __('annual_plans.change_the_fiscal_years_or_the_manager_and_try_again') : __('annual_plans.add_a_program_before_planning_its_year')"
                >
                    @if ($filtersAreActive)
                        <x-slot:action>
                            <flux:button size="sm" :class="$secondaryButtonClasses" wire:click="clearFilters">{{ __('common.reset_filters') }}</flux:button>
                        </x-slot:action>
                    @endif
                </x-ui.empty-state>
            @else
                {{-- Narrow layout: one card per program, its planned years listed. --}}
                <div class="divide-y divide-board-line @4xl/matrix:hidden">
                    @foreach ($this->programRows as $program)
                        @php($rowPlans = collect($yearColumns)->map(fn (int $year) => $this->planFor($program->id, $year))->filter())
                        <article
                            @class([
                                'flex flex-col gap-3 px-4 py-4',
                                'bg-ground' => $loop->even,
                            ])
                            wire:key="mobile-program-{{ $program->id }}"
                            x-show="! gapsOnly || {{ $this->planFor($program->id, $activeYear ?? 0) === null ? 'true' : 'false' }}"
                        >
                            <div class="flex min-w-0 items-center gap-3">
                                <div class="grid size-10 shrink-0 place-items-center rounded-md bg-secondary-soft text-sm font-bold text-secondary-dark dark:bg-secondary-medium dark:text-white" aria-hidden="true">
                                    {{ mb_strtoupper(mb_substr($program->name, 0, 1)) }}
                                </div>
                                <div class="min-w-0 flex-1">
                                    <h3 class="truncate text-sm font-bold">{{ $program->name }}</h3>
                                    @if ($program->strategy)
                                        <span class="mt-0.5 inline-block max-w-full truncate rounded-full border border-board-line px-1.5 py-px type-meta text-secondary-medium dark:text-secondary-soft">{{ $program->strategy->name }}</span>
                                    @else
                                        <p class="truncate type-meta text-secondary-light">{{ __('common.strategy_not_assigned') }}</p>
                                    @endif
                                </div>
                                <span class="ms-auto shrink-0 type-meta font-semibold tabular-nums text-secondary-medium dark:text-secondary-soft">{{ $rowPlans->count() }}/{{ count($yearColumns) }}</span>
                            </div>

                            {{-- One full-width row per fiscal year: each carries its own edit and delete controls, which a two-column grid leaves no room for at phone widths. --}}
                            <ul class="grid gap-2">
                                @foreach ($yearColumns as $year)
                                    @php($plan = $this->planFor($program->id, $year))
                                    @php($stats = $plan === null ? null : ($delivery[$plan->id] ?? ['targets' => 0, 'attributed' => 0, 'reports' => 0, 'approved' => 0]))
                                    <li wire:key="mobile-cell-{{ $program->id }}-{{ $year }}">
                                        @if ($plan === null)
                                            <button
                                                type="button"
                                                wire:click="openCreateModal({{ $program->id }}, {{ $year }})"
                                                class="flex w-full min-h-11 touch-manipulation items-center justify-between gap-3 rounded-md border border-dashed border-board-line px-3 py-2 text-start transition-colors hover:border-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                                                aria-label="{{ __('annual_plans.add_a_plan_for_program_in_year', ['program' => $program->name, 'year' => $year]) }}"
                                            >
                                                <span class="type-meta font-bold tabular-nums text-secondary-light">{{ $year }}</span>
                                                <span class="inline-flex items-center gap-1 type-meta text-secondary-medium dark:text-secondary-soft">
                                                    <flux:icon.plus class="size-3.5" />
                                                    {{ __('annual_plans.add_plan') }}
                                                </span>
                                            </button>
                                        @else
                                            <div class="flex items-stretch gap-1.5">
                                            <a
                                                href="{{ route('annual-plans.show', $plan) }}"
                                                wire:navigate
                                                @class([
                                                    'flex min-w-0 flex-1 min-h-11 flex-col justify-center gap-1 rounded-md border px-3 py-2 transition-colors hover:border-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                                                    'border-primary' => $year === $activeYear,
                                                    'border-board-line' => $year !== $activeYear,
                                                ])
                                                aria-label="{{ __('annual_plans.program_year_managed_by_manager_count_targets', ['program' => $program->name, 'year' => $year, 'manager' => $plan->manager?->name ?? __('common.not_assigned'), 'count' => $stats['targets']]) }}"
                                            >
                                                <span class="flex items-center justify-between gap-2">
                                                    <span class="type-meta font-bold tabular-nums">{{ $year }}</span>
                                                    <span class="inline-flex items-center gap-1.5">
                                                        @if ($stats['targets'] === 0)
                                                            <span aria-hidden="true" class="size-1.5 rounded-full bg-primary"></span>
                                                        @endif
                                                        <span class="text-sm font-bold tabular-nums">{{ $stats['targets'] }}</span>
                                                    </span>
                                                </span>
                                                <span class="truncate type-meta text-secondary-medium dark:text-secondary-soft">{{ $plan->manager?->name ?? __('common.not_assigned') }}</span>
                                            </a>

                                                <x-ui.button
                                                    tone="ghost"
                                                    size="sm"
                                                    icon="pencil-square"
                                                    class="size-11! shrink-0 touch-manipulation"
                                                    wire:click="openEditModal({{ $plan->id }})"
                                                    :aria-label="__('annual_plans.edit_the_plan_for_program_in_year', ['program' => $program->name, 'year' => $year])"
                                                />
                                                <x-ui.button
                                                    tone="ghost"
                                                    size="sm"
                                                    icon="trash"
                                                    class="size-11! shrink-0 touch-manipulation"
                                                    wire:click="confirmDelete({{ $plan->id }})"
                                                    :aria-label="__('annual_plans.delete_the_plan_for_program_in_year', ['program' => $program->name, 'year' => $year])"
                                                />
                                            </div>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </article>
                    @endforeach
                </div>

                {{-- Wide layout: the grid. Rows are programs, columns fiscal years. --}}
                <div class="hidden overflow-x-auto [scrollbar-width:thin] @4xl/matrix:block">
                    <div
                        role="table"
                        aria-labelledby="coverage-matrix-heading"
                        class="w-full min-w-[46rem]"
                        style="--matrix-columns: {{ count($yearColumns) }}"
                    >
                        {{-- Neither this row nor the Program column below is position:sticky. An `overflow-x-auto` ancestor computes `overflow-y: auto` too (CSS Overflow §3), quietly making it a sticky containing block on both axes — a `start-0` column stuck to that box has floated to the top of the page and over the app's own header before, so the Program column simply scrolls with the rest of the row now. --}}
                        <div role="row" class="grid border-b border-board-line bg-board-head" style="grid-template-columns: minmax(15rem, 26rem) repeat(var(--matrix-columns), minmax(6.25rem, 14rem)) 1fr;">
                            <div role="columnheader" class="flex items-end bg-board-head px-5 py-3">
                                <span class="type-label text-secondary-medium dark:text-secondary-soft">{{ __('common.program') }}</span>
                            </div>

                            @foreach ($yearRail as $rail)
                                <div role="columnheader" class="px-1.5 py-2" wire:key="head-{{ $rail['year'] }}">
                                    <button
                                        type="button"
                                        wire:click="focusOnYear({{ $rail['year'] }})"
                                        x-on:mouseenter="hoverYear = {{ $rail['year'] }}"
                                        x-on:mouseleave="hoverYear = null"
                                        x-on:focus="hoverYear = {{ $rail['year'] }}"
                                        x-on:blur="hoverYear = null"
                                        aria-pressed="{{ $rail['isActive'] ? 'true' : 'false' }}"
                                        aria-label="{{ __('annual_plans.focus_year_plans_targets', ['year' => $rail['year'], 'plans' => $rail['plans'], 'targets' => $rail['targets']]) }}"
                                        @class([
                                            'flex w-full cursor-pointer flex-col items-center gap-1 rounded-md border-2 px-2 py-1.5 transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                                            'border-primary' => $rail['isActive'],
                                            'border-transparent hover:border-board-line' => ! $rail['isActive'],
                                        ])
                                    >
                                        <span class="text-sm font-bold tabular-nums">{{ $rail['year'] }}</span>
                                        <span class="type-meta font-normal text-secondary-medium tabular-nums dark:text-secondary-soft">({{ $rail['plans'] }})</span>
                                        <span class="h-1 w-full overflow-hidden rounded-full bg-board-line" aria-hidden="true">
                                            <span class="block h-full rounded-full bg-primary" style="width: {{ $rail['coverage'] }}%"></span>
                                        </span>
                                    </button>
                                </div>
                            @endforeach
                        </div>

                        @foreach ($this->programRows as $program)
                            @php($rowPlans = collect($yearColumns)->map(fn (int $year) => $this->planFor($program->id, $year))->filter())
                            @php($rowTargets = $rowPlans->sum(fn (\App\Models\AnnualPlan $plan): int => $delivery[$plan->id]['targets'] ?? 0))
                            <div
                                role="row"
                                @class([
                                    'group/row grid border-b border-board-line transition-colors last:border-b-0',
                                    'bg-ground' => $loop->even,
                                    'bg-board' => ! $loop->even,
                                ])
                                style="grid-template-columns: minmax(15rem, 26rem) repeat(var(--matrix-columns), minmax(6.25rem, 14rem)) 1fr;"
                                wire:key="row-{{ $program->id }}"
                                x-show="! gapsOnly || {{ $this->planFor($program->id, $activeYear ?? 0) === null ? 'true' : 'false' }}"
                                x-on:mouseenter="hoverProgram = {{ $program->id }}"
                                x-on:mouseleave="hoverProgram = null"
                                x-bind:class="hoverProgram === {{ $program->id }} ? 'bg-board-head' : ''"
                            >
                                <div role="rowheader" class="flex min-w-0 items-center gap-3 bg-inherit px-5 py-3">
                                    <div class="grid size-9 shrink-0 place-items-center rounded-md bg-secondary-soft text-sm font-bold text-secondary-dark dark:bg-secondary-medium dark:text-white" aria-hidden="true">
                                        {{ mb_strtoupper(mb_substr($program->name, 0, 1)) }}
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-bold">{{ $program->name }}</p>
                                        @if ($program->strategy)
                                            <span class="mt-0.5 inline-block max-w-full truncate rounded-full border border-board-line px-1.5 py-px type-meta text-secondary-medium dark:text-secondary-soft">{{ $program->strategy->name }}</span>
                                        @else
                                            <p class="truncate type-meta text-secondary-light">{{ __('common.strategy_not_assigned') }}</p>
                                        @endif
                                        <div class="mt-1.5 flex items-center gap-1" aria-hidden="true">
                                            @foreach ($yearColumns as $coverageYear)
                                                <span @class([
                                                    'size-1.5 shrink-0 rounded-full',
                                                    'bg-primary' => $this->planFor($program->id, $coverageYear) !== null,
                                                    'bg-secondary-soft dark:bg-secondary-medium' => $this->planFor($program->id, $coverageYear) === null,
                                                ])></span>
                                            @endforeach
                                        </div>
                                    </div>
                                    <div class="shrink-0 text-end">
                                        <p class="text-sm font-bold tabular-nums">{{ $rowPlans->count() }}<span class="font-medium text-secondary-medium dark:text-secondary-soft">/{{ count($yearColumns) }}</span></p>
                                        <p class="type-meta text-secondary-medium tabular-nums dark:text-secondary-soft">{{ trans_choice('common.count_target', $rowTargets, ['count' => $rowTargets]) }}</p>
                                    </div>
                                </div>

                                @foreach ($yearColumns as $year)
                                    @php($plan = $this->planFor($program->id, $year))
                                    @php($stats = $plan === null ? null : ($delivery[$plan->id] ?? ['targets' => 0, 'attributed' => 0, 'reports' => 0, 'approved' => 0]))
                                    <div
                                        role="cell"
                                        class="group/cell relative p-1.5 transition-colors"
                                        wire:key="cell-{{ $program->id }}-{{ $year }}"
                                        x-bind:class="hoverYear === {{ $year }} ? 'bg-board-head' : ''"
                                    >
                                        @if ($plan === null)
                                            {{-- A gap is the one thing a coverage matrix exists to show, so it is also the fastest place to close it: the cell writes the plan it is missing. --}}
                                            <button
                                                type="button"
                                                wire:click="openCreateModal({{ $program->id }}, {{ $year }})"
                                                x-on:mouseenter="hoverYear = {{ $year }}"
                                                x-on:mouseleave="hoverYear = null"
                                                x-on:focus="hoverYear = {{ $year }}"
                                                x-on:blur="hoverYear = null"
                                                @class([
                                                    'group/gap flex h-full w-full min-h-14 items-center justify-center rounded-md border border-dashed transition-colors hover:border-primary hover:bg-board focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                                                    'border-primary' => $year === $activeYear,
                                                    'border-secondary-soft dark:border-secondary-medium' => $year !== $activeYear,
                                                ])
                                                aria-label="{{ __('annual_plans.add_a_plan_for_program_in_year', ['program' => $program->name, 'year' => $year]) }}"
                                            >
                                                <span aria-hidden="true" class="text-secondary-light group-hover/gap:hidden">—</span>
                                                <flux:icon.plus aria-hidden="true" class="hidden size-4 text-primary group-hover/gap:block" />
                                            </button>
                                        @else
                                            @php($reportShare = $stats['reports'] > 0 ? (int) round($stats['approved'] / $stats['reports'] * 100) : 0)
                                            <a
                                                href="{{ route('annual-plans.show', $plan) }}"
                                                wire:navigate
                                                x-on:mouseenter="hoverYear = {{ $year }}"
                                                x-on:mouseleave="hoverYear = null"
                                                x-on:focus="hoverYear = {{ $year }}"
                                                x-on:blur="hoverYear = null"
                                                @class([
                                                    'relative flex h-full min-h-14 flex-col items-center justify-center gap-1.5 overflow-hidden rounded-md border bg-board transition-[transform,border-color,box-shadow] duration-150 hover:-translate-y-0.5 hover:border-primary hover:shadow-[0_10px_20px_-14px_rgb(0_0_0/0.55)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary motion-reduce:transition-none motion-reduce:hover:translate-y-0',
                                                    'border-primary' => $year === $activeYear,
                                                    'border-secondary-soft dark:border-secondary-medium' => $year !== $activeYear,
                                                ])
                                                aria-label="{{ __('annual_plans.program_year_managed_by_manager_count_targets', ['program' => $program->name, 'year' => $year, 'manager' => $plan->manager?->name ?? __('common.not_assigned'), 'count' => $stats['targets']]) }}"
                                            >
                                                @if ($stats['targets'] === 0)
                                                    {{-- A flat primary dot flags an open plan with nothing committed yet; the number itself stays full-contrast ink in both themes. --}}
                                                    <span aria-hidden="true" class="absolute end-1.5 top-1.5 size-1.5 rounded-full bg-primary"></span>
                                                @endif
                                                <span class="text-xl font-bold leading-none tabular-nums text-secondary-dark dark:text-white">{{ $stats['targets'] }}</span>

                                                @if ($stats['reports'] > 0)
                                                    <span class="h-1 w-8 overflow-hidden rounded-full bg-secondary-soft dark:bg-secondary-medium" aria-hidden="true">
                                                        <span class="block h-full rounded-full bg-status-good" style="width: {{ $reportShare }}%"></span>
                                                    </span>
                                                @else
                                                    <span class="h-1 w-8" aria-hidden="true"></span>
                                                @endif

                                                <span class="pointer-events-none absolute inset-x-0 bottom-0 flex translate-y-full flex-col gap-0.5 bg-secondary-dark px-1.5 py-1 text-center transition-transform duration-200 group-hover/cell:translate-y-0 motion-reduce:transition-none" aria-hidden="true">
                                                    <span class="truncate text-[0.625rem] font-semibold text-white">{{ $plan->manager?->name ?? __('common.not_assigned') }}</span>
                                                    @if ($stats['reports'] > 0)
                                                        <span class="text-[0.625rem] tabular-nums text-secondary-soft">{{ __('annual_plans.approved') }} {{ $stats['approved'] }}/{{ $stats['reports'] }}</span>
                                                    @endif
                                                </span>
                                            </a>

                                            {{-- Siblings of the link, not children: a cell is a link first, so its controls sit over it and only surface on hover or keyboard focus. --}}
                                            <div class="absolute start-2.5 top-2.5 flex gap-0.5 opacity-0 transition-opacity group-hover/cell:opacity-100 group-focus-within/cell:opacity-100 motion-reduce:transition-none">
                                                <button
                                                    type="button"
                                                    wire:click="openEditModal({{ $plan->id }})"
                                                    class="grid size-5 place-items-center rounded border border-secondary-soft bg-board text-secondary-medium transition-colors hover:border-primary hover:text-primary focus-visible:opacity-100 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary dark:border-secondary-medium dark:text-secondary-soft"
                                                    aria-label="{{ __('annual_plans.edit_the_plan_for_program_in_year', ['program' => $program->name, 'year' => $year]) }}"
                                                >
                                                    <flux:icon.pencil-square class="size-3" />
                                                </button>
                                                <button
                                                    type="button"
                                                    wire:click="confirmDelete({{ $plan->id }})"
                                                    class="grid size-5 place-items-center rounded border border-secondary-soft bg-board text-secondary-medium transition-colors hover:border-primary hover:text-primary focus-visible:opacity-100 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary dark:border-secondary-medium dark:text-secondary-soft"
                                                    aria-label="{{ __('annual_plans.delete_the_plan_for_program_in_year', ['program' => $program->name, 'year' => $year]) }}"
                                                >
                                                    <flux:icon.trash class="size-3" />
                                                </button>
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="border-t border-board-line px-4 pb-3">
                    <flux:pagination :paginator="$this->programRows" class="text-secondary-medium! [&_button]:text-secondary-medium! [&_button:hover]:bg-secondary-soft! dark:text-secondary-soft! dark:[&_button]:text-secondary-soft! dark:[&_button:hover]:bg-secondary-medium!" scroll-to="#annual-plan-matrix-heading" />
                </div>
            @endif
        </section>
    @endif

    <flux:modal wire:model="showPlanModal" class="w-full max-w-2xl border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        <form wire:submit="savePlan" class="flex flex-col gap-5">
            <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                <flux:heading size="lg">{{ $editingPlanId === null ? __('annual_plans.new_annual_plan') : __('annual_plans.edit_annual_plan') }}</flux:heading>
                <flux:subheading>{{ __('annual_plans.one_program_one_fiscal_year_one_accountable_manager') }}</flux:subheading>
            </div>

            @if ($showPlanModal)
                <x-ui.select wire:model="planProgramId" :label="__('common.program')" required>
                    <flux:select.option value="">{{ __('annual_plans.select_a_program') }}</flux:select.option>
                    @foreach ($this->programOptions as $programOption)
                        <flux:select.option :value="$programOption->id" :wire:key="'plan-program-option-'.$programOption->id">{{ $programOption->name }}</flux:select.option>
                    @endforeach
                </x-ui.select>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.select wire:model="planYear" :label="__('annual_plans.fiscal_year')" required>
                        <flux:select.option value="">{{ __('annual_plans.select_a_fiscal_year') }}</flux:select.option>
                        @foreach ($this->yearFormOptions as $yearFormOption)
                            <flux:select.option :value="$yearFormOption" :wire:key="'plan-year-option-'.$yearFormOption">{{ $yearFormOption }}</flux:select.option>
                        @endforeach
                    </x-ui.select>

                    <x-ui.select wire:model="planManagerId" :label="__('annual_plans.assigned_manager')" required>
                        <flux:select.option value="">{{ __('annual_plans.select_a_manager') }}</flux:select.option>
                        @foreach ($this->employeeOptions as $employeeOption)
                            <flux:select.option :value="$employeeOption->id" :wire:key="'plan-manager-option-'.$employeeOption->id">
                                {{ $employeeOption->name }} · {{ $employeeOption->secondary_name }}
                            </flux:select.option>
                        @endforeach
                    </x-ui.select>
                </div>
            @endif

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" wire:click="closePlanModal">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" data-test="save-annual-plan-button">
                    {{ $editingPlanId === null ? __('annual_plans.create_annual_plan') : __('common.save_changes') }}
                </x-ui.button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model="showDeleteModal" wire:close="cancelDelete" class="w-full max-w-md border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        @php($pendingDelete = $this->deletingPlan)
        <div class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('annual_plans.delete_this_annual_plan') }}</flux:heading>
            @if ($pendingDelete !== null)
                <flux:subheading>
                    {{ $pendingDelete['targets'] > 0
                        ? __('annual_plans.deleting_the_plan_also_removes_its_targets_and_their_reports', [
                            'program' => $pendingDelete['plan']->program?->name ?? __('common.program'),
                            'year' => $pendingDelete['plan']->year,
                            'count' => $pendingDelete['targets'],
                        ])
                        : __('annual_plans.this_plan_has_no_targets_committed_in_it', [
                            'program' => $pendingDelete['plan']->program?->name ?? __('common.program'),
                            'year' => $pendingDelete['plan']->year,
                        ]) }}
                </flux:subheading>
            @endif
            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" wire:click="cancelDelete">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button type="button" tone="primary" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" wire:click="deletePlan" data-test="confirm-delete-annual-plan">{{ __('annual_plans.delete_annual_plan') }}</x-ui.button>
            </div>
        </div>
    </flux:modal>
</section>
