<?php

use App\Enums\ReportStatus;
use App\Models\HrEmployee;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Strategy;
use App\Models\AnnualPlan;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\JoinClause;
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

    #[Url(except: '')]
    public string $yearFrom = '';

    #[Url(except: '')]
    public string $yearTo = '';

    #[Url(except: '')]
    public string $manager = '';

    #[Url(except: '')]
    public string $strategy = '';

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

    public function updatedYearFrom(): void
    {
        $this->resetPage();
    }

    public function updatedYearTo(): void
    {
        $this->resetPage();
    }

    public function updatedManager(): void
    {
        $this->resetPage();
    }

    public function updatedStrategy(): void
    {
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
        $this->yearFrom = '';
        $this->yearTo = '';
        $this->manager = '';
        $this->strategy = '';
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
     * The fiscal years the grid spans, after defaults, ordering and the column
     * cap have been applied.
     *
     * Null means there is nothing to span because no plan exists yet. An
     * explicit From anchors the window at its start; otherwise the window is
     * anchored at its end, so the default view shows the most recent years
     * rather than the oldest.
     *
     * @return array{from: int, to: int, clamped: bool}|null
     */
    #[Computed]
    public function yearRange(): ?array
    {
        $years = $this->yearOptions;

        if ($years === []) {
            return null;
        }

        $anchoredAtStart = $this->yearFrom !== '';
        $from = $anchoredAtStart ? (int) $this->yearFrom : $years[0];
        $to = $this->yearTo === '' ? $years[count($years) - 1] : (int) $this->yearTo;

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $clamped = ($to - $from + 1) > self::MAX_YEAR_COLUMNS;

        if ($clamped) {
            if ($anchoredAtStart) {
                $to = $from + self::MAX_YEAR_COLUMNS - 1;
            } else {
                $from = $to - self::MAX_YEAR_COLUMNS + 1;
            }
        }

        return ['from' => $from, 'to' => $to, 'clamped' => $clamped];
    }

    /**
     * The fiscal years rendered as columns, gaps included.
     *
     * A year no program planned still gets a column: an empty column is the
     * point of a coverage matrix.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function yearColumns(): array
    {
        $range = $this->yearRange;

        return $range === null ? [] : range($range['from'], $range['to']);
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
     * The strategies at least one program is assigned to.
     *
     * @return Collection<int, Strategy>
     */
    #[Computed]
    public function strategyOptions(): Collection
    {
        return Strategy::query()
            ->select(['id', 'name', 'start_year', 'end_year'])
            ->whereHas('programs')
            ->orderByDesc('start_year')
            ->orderBy('name')
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
        $range = $this->yearRange;
        $managerId = $this->selectedManagerId();

        return Program::query()
            ->select(['id', 'name', 'strategy_id'])
            ->with(['strategy:id,name,start_year,end_year'])
            ->when($managerId !== null, fn (Builder $query) => $query->whereHas(
                'annualPlans',
                function (Builder $query) use ($managerId, $range): void {
                    $query->managedBy($managerId);

                    if ($range !== null) {
                        $query->forYears($range['from'], $range['to']);
                    }
                },
            ))
            ->when($this->strategy !== '', fn (Builder $query) => $query->where('strategy_id', (int) $this->strategy))
            ->when($this->programSearch !== '', fn (Builder $query) => $query->where('name', 'like', '%'.$this->programSearch.'%'))
            ->orderBy('name')
            ->orderBy('id')
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
        $range = $this->yearRange;
        $programIds = $this->programRows->getCollection()->modelKeys();

        if ($range === null || $programIds === []) {
            return [];
        }

        return AnnualPlan::query()
            ->select(['id', 'program_id', 'manager_id', 'year'])
            ->whereIn('program_id', $programIds)
            ->forYears($range['from'], $range['to'])
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
        $range = $this->yearRange;

        if ($range === null) {
            return [];
        }

        return AnnualPlan::query()
            ->leftJoin('plan_indicators', fn (JoinClause $join) => $join
                ->on('plan_indicators.source_id', '=', 'annual_plans.id')
                ->where('plan_indicators.source_type', '=', 'annual_plan'))
            ->leftJoin('reports', 'reports.plan_indicator_id', '=', 'plan_indicators.id')
            ->forYears($range['from'], $range['to'])
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
    $filtersAreActive = $yearFrom !== '' || $yearTo !== '' || $manager !== '' || $strategy !== '' || $programSearch !== '';
@endphp

<section
    class="admin-directory @container/matrix flex min-h-full w-full flex-1 flex-col gap-8 bg-white px-1 text-secondary-dark dark:bg-secondary-dark dark:text-white"
    aria-labelledby="annual-plan-matrix-heading"
    x-data="{ hoverYear: null, hoverProgram: null, gapsOnly: false }"
>
    <header class="grid gap-8 border-b border-secondary-soft pb-7 @6xl/matrix:grid-cols-[minmax(16rem,.7fr)_minmax(0,1.3fr)] @6xl/matrix:items-center dark:border-secondary-medium">
        <div class="min-w-0">
            <div class="flex items-center gap-2.5">
                <flux:heading id="annual-plan-matrix-heading" size="xl" class="text-3xl! font-bold! leading-none!">{{ __('common.annual_plans') }}</flux:heading>
                <span class="inline-flex min-w-7 items-center justify-center rounded-md bg-primary px-2 py-0.5 text-xs font-bold tabular-nums text-white">{{ array_sum(array_column($yearStats, 'plans')) }}</span>
            </div>
        </div>

        <div class="directory-filters grid gap-3 @3xl/matrix:grid-cols-2 @6xl/matrix:grid-cols-3">
            <div class="min-w-0">
                <label for="annual-plan-year-from" class="mb-1.5 block text-[11px] font-semibold uppercase tracking-[0.1em] text-secondary-medium dark:text-secondary-soft">{{ __('annual_plans.from_fiscal_year') }}</label>
                <flux:select id="annual-plan-year-from" :aria-label="__('annual_plans.from_fiscal_year')" wire:model.live="yearFrom">
                    <flux:select.option value="">{{ __('annual_plans.earliest') }}</flux:select.option>
                    @foreach ($this->yearOptions as $yearOption)
                        <flux:select.option :value="$yearOption" :wire:key="'year-from-'.$yearOption">{{ $yearOption }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="min-w-0">
                <label for="annual-plan-year-to" class="mb-1.5 block text-[11px] font-semibold uppercase tracking-[0.1em] text-secondary-medium dark:text-secondary-soft">{{ __('annual_plans.to_fiscal_year') }}</label>
                <flux:select id="annual-plan-year-to" :aria-label="__('annual_plans.to_fiscal_year')" wire:model.live="yearTo">
                    <flux:select.option value="">{{ __('annual_plans.latest') }}</flux:select.option>
                    @foreach ($this->yearOptions as $yearOption)
                        <flux:select.option :value="$yearOption" :wire:key="'year-to-'.$yearOption">{{ $yearOption }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="min-w-0">
                <label for="annual-plan-manager" class="mb-1.5 block text-[11px] font-semibold uppercase tracking-[0.1em] text-secondary-medium dark:text-secondary-soft">{{ __('annual_plans.assigned_manager') }}</label>
                <flux:select id="annual-plan-manager" :aria-label="__('annual_plans.assigned_manager')" wire:model.live="manager">
                    <flux:select.option value="">{{ __('common.all_managers') }}</flux:select.option>
                    @foreach ($this->managerOptions as $managerOption)
                        <flux:select.option :value="$managerOption->id" :wire:key="'manager-filter-'.$managerOption->id">
                            {{ $managerOption->name }} · {{ $managerOption->secondary_name }}
                        </flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </div>
    </header>

    <div class="flex justify-end">
        <span wire:loading.delay.flex wire:target="yearFrom,yearTo,manager,strategy,programSearch,clearFilters,focusOnYear" class="hidden items-center gap-1.5 text-xs text-secondary-medium dark:text-secondary-soft">
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
        />
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
                        {{ __('annual_plans.fiscal_years_from_to', ['from' => $yearRange['from'], 'to' => $yearRange['to']]) }}
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

            <div class="directory-filters grid gap-2 border-b border-board-line bg-board-head px-5 pb-3.5 @sm/matrix:grid-cols-[minmax(0,1fr)_13rem]">
                <x-ui.input
                    type="search"
                    icon="magnifying-glass"
                    wire:model.live.debounce.300ms="programSearch"
                    :aria-label="__('annual_plans.search_programs')"
                    :placeholder="__('annual_plans.search_programs_placeholder')"
                />
                <flux:select :aria-label="__('common.strategy')" wire:model.live="strategy">
                    <flux:select.option value="">{{ __('programs.all_strategies') }}</flux:select.option>
                    @foreach ($this->strategyOptions as $strategyOption)
                        <flux:select.option :value="$strategyOption->id" :wire:key="'matrix-strategy-'.$strategyOption->id">{{ $strategyOption->name }}</flux:select.option>
                    @endforeach
                </flux:select>
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
                <div class="divide-y divide-board-line @5xl/matrix:hidden">
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

                            <ul class="grid gap-2 @xs/matrix:grid-cols-2">
                                @foreach ($yearColumns as $year)
                                    @php($plan = $this->planFor($program->id, $year))
                                    @php($stats = $plan === null ? null : ($delivery[$plan->id] ?? ['targets' => 0, 'attributed' => 0, 'reports' => 0, 'approved' => 0]))
                                    <li wire:key="mobile-cell-{{ $program->id }}-{{ $year }}">
                                        @if ($plan === null)
                                            <div class="flex min-h-11 items-center justify-between gap-3 rounded-md border border-dashed border-board-line px-3 py-2" aria-label="{{ __('annual_plans.program_year_no_plan', ['program' => $program->name, 'year' => $year]) }}">
                                                <span class="type-meta font-bold tabular-nums text-secondary-light">{{ $year }}</span>
                                                <span class="type-meta text-secondary-light">{{ __('annual_plans.no_plan') }}</span>
                                            </div>
                                        @else
                                            <a
                                                href="{{ route('annual-plans.show', $plan) }}"
                                                wire:navigate
                                                @class([
                                                    'flex min-h-11 flex-col justify-center gap-1 rounded-md border px-3 py-2 transition-colors hover:border-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
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
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </article>
                    @endforeach
                </div>

                {{-- Wide layout: the grid. Rows are programs, columns fiscal years. --}}
                <div class="hidden overflow-x-auto [scrollbar-width:thin] @5xl/matrix:block">
                    <div
                        role="table"
                        aria-labelledby="coverage-matrix-heading"
                        class="w-full min-w-[46rem]"
                        style="--matrix-columns: {{ count($yearColumns) }}"
                    >
                        {{-- Neither this row nor the Program column below is position:sticky. An `overflow-x-auto` ancestor computes `overflow-y: auto` too (CSS Overflow §3), quietly making it a sticky containing block on both axes — a `start-0` column stuck to that box has floated to the top of the page and over the app's own header before, so the Program column simply scrolls with the rest of the row now. --}}
                        <div role="row" class="grid border-b border-board-line bg-board-head" style="grid-template-columns: minmax(15rem, 1fr) repeat(var(--matrix-columns), minmax(6.25rem, 9rem));">
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
                                style="grid-template-columns: minmax(15rem, 1fr) repeat(var(--matrix-columns), minmax(6.25rem, 9rem));"
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
                                        class="p-1.5 transition-colors"
                                        wire:key="cell-{{ $program->id }}-{{ $year }}"
                                        x-bind:class="hoverYear === {{ $year }} ? 'bg-board-head' : ''"
                                    >
                                        @if ($plan === null)
                                            <div
                                                @class([
                                                    'flex h-full min-h-14 items-center justify-center rounded-md border border-dashed',
                                                    'border-primary' => $year === $activeYear,
                                                    'border-secondary-soft dark:border-secondary-medium' => $year !== $activeYear,
                                                ])
                                                aria-label="{{ __('annual_plans.program_year_no_plan', ['program' => $program->name, 'year' => $year]) }}"
                                            >
                                                <span aria-hidden="true" class="text-secondary-light">—</span>
                                            </div>
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
                                                    'group/cell relative flex h-full min-h-14 flex-col items-center justify-center gap-1.5 overflow-hidden rounded-md border bg-board transition-[transform,border-color,box-shadow] duration-150 hover:-translate-y-0.5 hover:border-primary hover:shadow-[0_10px_20px_-14px_rgb(0_0_0/0.55)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary motion-reduce:transition-none motion-reduce:hover:translate-y-0',
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
</section>
