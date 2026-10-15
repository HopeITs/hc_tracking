<?php

use App\Livewire\Concerns\Strategies\ManagesStrategicPillars;
use App\Models\AnnualPlan;
use App\Models\Program;
use App\Models\Strategy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('titles.strategy')] class extends Component {
    use ManagesStrategicPillars;
    use WithPagination;

    #[Locked]
    public int $strategyId;

    #[Url(except: 'name')]
    public string $sort = 'name';

    #[Url(except: 'asc')]
    public string $direction = 'asc';

    public function mount(Strategy $strategy): void
    {
        $this->strategyId = $strategy->id;
    }

    public function sortBy(string $column): void
    {
        if (! in_array($column, ['name', 'projects', 'plans'], true)) {
            return;
        }

        if ($this->sort === $column) {
            $this->direction = $this->direction === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort = $column;
            $this->direction = 'asc';
        }

        $this->resetPage('programsPage');
    }

    #[Computed]
    public function strategy(): Strategy
    {
        return Strategy::query()
            ->select(['id', 'name', 'start_year', 'end_year', 'updated_at'])
            ->withCount('programs')
            ->findOrFail($this->strategyId);
    }

    /** @return Builder<Program> */
    protected function programsQuery(): Builder
    {
        return Program::query()
            ->select(['id', 'strategy_id', 'strategic_pillar_id', 'manager_id'])
            ->where('strategy_id', $this->strategyId)
            ->with(['manager:id,first_name_en,last_name_en,first_name_ar,last_name_ar'])
            ->withCount(['projects', 'annualPlans']);
    }

    /**
     * Every program under this strategy, unpaginated. totals(), planCoverage()
     * and averageCoverage() need the whole portfolio, not one page of the
     * table, so they read from here rather than from programs().
     *
     * @return Collection<int, Program>
     */
    #[Computed]
    public function allPrograms(): Collection
    {
        return $this->programsQuery()->get();
    }

    /** @return LengthAwarePaginator<int, Program> */
    #[Computed]
    public function programs(): LengthAwarePaginator
    {
        $direction = $this->direction === 'desc' ? 'desc' : 'asc';

        $query = match ($this->sort) {
            'projects' => $this->programsQuery()->orderBy('projects_count', $direction),
            'plans' => $this->programsQuery()->orderBy('annual_plans_count', $direction),
            default => $this->programsQuery()->orderByName($direction),
        };

        return $query
            ->orderBy('id')
            ->paginate(15, pageName: 'programsPage');
    }

    /**
     * Every (program, year) pair inside the strategy's span whose annual plan
     * has at least one committed indicator target, as a fast
     * "program:year" => true lookup.
     *
     * An empty AnnualPlan shell (no plan_indicators yet) does not count as
     * covered — coverage must reflect real target commitments, not just a
     * planning row existing. planIndicators::target is a required column, so
     * having any plan_indicators row is equivalent to having a real target.
     *
     * One query for the whole page; planCoverage() and yearlyProgramCoverage()
     * both tally it in memory instead of querying again.
     *
     * @return array<string, true>
     */
    #[Computed]
    public function plannedYears(): array
    {
        $strategy = $this->strategy;
        $programIds = $this->allPrograms->pluck('id');

        if ($programIds->isEmpty()) {
            return [];
        }

        return AnnualPlan::query()
            ->whereIn('program_id', $programIds)
            ->whereBetween('year', [$strategy->start_year, $strategy->end_year])
            ->whereHas('planIndicators')
            ->get(['program_id', 'year'])
            ->mapWithKeys(fn (AnnualPlan $plan) => [$plan->program_id.':'.$plan->year => true])
            ->all();
    }

    /**
     * Distinct plan-years recorded per program, within this strategy's span.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function planCoverage(): array
    {
        $planned = $this->plannedYears;
        $counts = [];

        foreach (array_keys($planned) as $key) {
            [$programId] = explode(':', $key);
            $counts[(int) $programId] = ($counts[(int) $programId] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * How many programs have a plan for each year in the strategy's span, for
     * the heat strip: [year => count of programs planned that year].
     *
     * @return array<int, int>
     */
    #[Computed]
    public function yearlyProgramCoverage(): array
    {
        $strategy = $this->strategy;
        $planned = $this->plannedYears;
        $counts = array_fill_keys(range($strategy->start_year, $strategy->end_year), 0);

        foreach (array_keys($planned) as $key) {
            [, $year] = explode(':', $key);
            $counts[(int) $year] = ($counts[(int) $year] ?? 0) + 1;
        }

        return $counts;
    }

    #[Computed]
    public function totals(): array
    {
        return [
            'projects' => (int) $this->allPrograms->sum('projects_count'),
            'plans' => (int) $this->allPrograms->sum('annual_plans_count'),
        ];
    }

    /**
     * Share of every program's span-years that carry an annual plan, averaged
     * across the whole portfolio. Null when there are no programs to cover.
     */
    #[Computed]
    public function averageCoverage(): ?float
    {
        if ($this->allPrograms->isEmpty()) {
            return null;
        }

        $spanYears = $this->strategy->end_year - $this->strategy->start_year + 1;

        if ($spanYears <= 0) {
            return null;
        }

        $possible = $this->allPrograms->count() * $spanYears;
        $covered = array_sum($this->planCoverage);

        return min(100, ($covered / $possible) * 100);
    }
};
?>

@php
    $strategy = $this->strategy;
    $currentYear = now()->year;
    $spanYears = $strategy->end_year - $strategy->start_year + 1;
    $spanYearsList = range($strategy->start_year, $strategy->end_year);
    $status = match (true) {
        $currentYear < $strategy->start_year => 'upcoming',
        $currentYear > $strategy->end_year => 'completed',
        default => 'active',
    };
    $yearOfSpan = $status === 'active' ? $currentYear - $strategy->start_year + 1 : null;

    $statusLabel = match ($status) {
        'active' => __('common.active'),
        'upcoming' => __('strategies.upcoming'),
        default => __('common.completed'),
    };

    $statusChipClasses = match ($status) {
        'active' => 'bg-status-good dark:bg-status-good-soft dark:text-status-good text-white',
        'upcoming' => 'bg-secondary-dark text-white dark:bg-ink dark:text-surface-0',
        default => 'bg-secondary-soft text-secondary-dark dark:bg-surface-2 dark:text-ink',
    };

    $sortAria = fn (string $column): ?string => $sort === $column ? ($direction === 'asc' ? 'ascending' : 'descending') : null;
@endphp

<section class="@container/detail flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink">
    <x-ui.detail-header
        :breadcrumbs="[
            ['label' => __('common.strategies'), 'url' => route('strategies.index')],
            ['label' => $strategy->name],
        ]"
        :title="$strategy->name"
        title-class="text-3xl! font-bold! leading-none!"
        :subtitle="__('strategies.strategic_period_start_to_end', ['start' => $strategy->start_year, 'end' => $strategy->end_year])"
    >
        <x-slot:meta>
            <span class="{{ $statusChipClasses }} inline-flex shrink-0 items-center rounded-md px-2.5 py-1 text-xs font-semibold">{{ $statusLabel }}</span>
            <span>{{ __('common.updated_date', ['date' => $strategy->updated_at?->translatedFormat(__('formats.date'))]) }}</span>
            @if ($yearOfSpan !== null)
                <span>{{ __('strategies.year_of_total', ['current' => $yearOfSpan, 'total' => $spanYears]) }}</span>
            @endif
        </x-slot:meta>
    </x-ui.detail-header>

    <div class="grid gap-3 @2xl/detail:grid-cols-3">
        <x-ui.stat-tile :label="__('common.programs')" :value="$strategy->programs_count" icon="briefcase" :href="route('programs.index', ['strategy' => $strategy->id])" />
        <x-ui.stat-tile :label="__('common.projects')" :value="$this->totals['projects']" icon="rectangle-stack" :href="route('projects.index', ['strategy' => $strategy->id])" />
        <x-ui.stat-tile :label="__('common.annual_plans')" :value="$this->totals['plans']" icon="calendar-days" :href="route('annual-plans.index', ['strategy' => $strategy->id])" />
    </div>

    @if ($this->allPrograms->isNotEmpty())
        @php
            $coveragePct = $this->averageCoverage ?? 0.0;
            $coverageTone = match (true) {
                $this->averageCoverage === null => null,
                $coveragePct >= 67 => 'good',
                $coveragePct >= 34 => 'funding',
                default => 'warn',
            };
            $coverageRingStrokeClass = match ($coverageTone) {
                'good' => 'stroke-status-good',
                'warn' => 'stroke-status-warn',
                'funding' => 'stroke-funding',
                default => 'stroke-secondary-medium dark:stroke-ink-muted',
            };
            $coverageTextClass = match ($coverageTone) {
                'good' => 'text-status-good',
                'warn' => 'text-status-warn',
                'funding' => 'text-funding',
                default => 'text-secondary-medium dark:text-ink-muted',
            };
            $ringRadius = 15.5;
            $ringCircumference = 2 * M_PI * $ringRadius;
            $ringOffset = $ringCircumference - ($ringCircumference * $coveragePct / 100);
        @endphp

        <x-ui.panel :title="__('strategies.plan_coverage')" :subtitle="__('strategies.plan_coverage_by_year')">
            <x-slot:toolbar>
                <div class="relative flex size-16 shrink-0 items-center justify-center" role="img" :aria-label="__('strategies.average_coverage').': '.round($coveragePct).'%'">
                    <svg class="size-16 -rotate-90" viewBox="0 0 36 36" aria-hidden="true">
                        <circle cx="18" cy="18" r="{{ $ringRadius }}" fill="none" stroke-width="3" class="stroke-secondary-soft dark:stroke-surface-2" />
                        <circle
                            cx="18" cy="18" r="{{ $ringRadius }}" fill="none" stroke-width="3" stroke-linecap="round"
                            class="{{ $coverageRingStrokeClass }} transition-[stroke-dashoffset] duration-500"
                            stroke-dasharray="{{ $ringCircumference }}"
                            stroke-dashoffset="{{ $ringOffset }}"
                        />
                    </svg>
                    <span class="{{ $coverageTextClass }} absolute text-base font-bold tabular-nums">{{ round($coveragePct) }}%</span>
                </div>
            </x-slot:toolbar>

            <div class="overflow-x-auto p-4">
                @php
                    $yearlyMax = max(1, $this->allPrograms->count());
                    $yearDotClasses = fn (int $count): string => match (true) {
                        $count <= 0 => 'border-2 border-dashed border-secondary-light bg-white dark:border-ink-faint dark:bg-surface-1',
                        $count / $yearlyMax > 0.75 => 'border-2 border-secondary-dark bg-secondary-dark dark:border-ink dark:bg-ink',
                        $count / $yearlyMax > 0.5 => 'border-2 border-secondary-dark bg-secondary-dark/60 dark:border-ink dark:bg-white/60',
                        $count / $yearlyMax > 0.25 => 'border-2 border-secondary-dark bg-secondary-dark/30 dark:border-ink dark:bg-white/30',
                        default => 'border-2 border-secondary-dark bg-secondary-dark/[0.12] dark:border-ink dark:bg-white/[0.15]',
                    };
                @endphp
                <div class="relative min-w-max px-4 py-2" role="img" aria-label="{{ __('strategies.plan_coverage_by_year') }}">
                    <div class="absolute inset-x-4 top-1/2 h-px -translate-y-1/2 bg-secondary-soft dark:bg-line-strong" aria-hidden="true"></div>
                    <div class="relative flex items-end justify-between gap-6">
                        @foreach ($spanYearsList as $year)
                            @php
                                $yearCount = $this->yearlyProgramCoverage[$year] ?? 0;
                            @endphp
                            <div class="flex flex-col items-center gap-1.5" title="{{ $year }}: {{ trans_choice('strategies.count_program', $yearCount, ['count' => $yearCount]) }}">
                                <span class="type-meta font-bold tabular-nums {{ $yearCount > 0 ? 'text-secondary-dark dark:text-ink' : 'text-secondary-light dark:text-ink-faint' }}">{{ $yearCount }}</span>
                                <span class="{{ $yearDotClasses($yearCount) }} size-3 shrink-0 rounded-full"></span>
                                <span class="type-meta text-secondary-medium dark:text-ink-muted">{{ $year }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </x-ui.panel>
    @endif

    @include('pages.strategies._show-pillars')

    <x-ui.panel :title="__('common.programs')" :count="$this->programs->total()">
        @if ($this->programs->isEmpty())
            <x-ui.empty-state
                icon="briefcase"
                :heading="__('common.no_programs_yet')"
                :body="__('strategies.programs_linked_to_this_strategy_will_appear_here')"
            >
                <x-slot:action>
                    <flux:button size="sm" :href="route('programs.index')" wire:navigate>{{ __('common.programs') }}</flux:button>
                </x-slot:action>
            </x-ui.empty-state>
        @else
            <x-ui.data-table :caption="__('strategies.delivery_programs_carried_by_this_strategy')">
                <x-slot:head>
                    @php
                        $sortIcon = fn (string $column) => match ($sortAria($column)) {
                            'ascending' => 'chevron-up',
                            'descending' => 'chevron-down',
                            default => 'chevron-up-down',
                        };
                        $sortIconClasses = fn (string $column) => $sortAria($column) === null
                            ? 'size-3.5 text-secondary-light dark:text-ink-faint'
                            : 'size-3.5 text-primary dark:text-brand-text';
                    @endphp

                    <th scope="col" aria-sort="{{ $sortAria('name') ?? 'none' }}">
                        <button type="button" wire:click="sortBy('name')" wire:loading.attr="disabled" wire:target="sortBy" class="inline-flex items-center gap-1 hover:text-secondary-dark dark:hover:text-ink" aria-label="{{ __('strategies.sort_by_name') }}">
                            {{ __('common.program') }} <flux:icon :name="$sortIcon('name')" :class="$sortIconClasses('name')" />
                        </button>
                    </th>
                    <th scope="col">{{ __('programs.manager') }}</th>
                    <th scope="col" class="text-end!" aria-sort="{{ $sortAria('projects') ?? 'none' }}">
                        <button type="button" wire:click="sortBy('projects')" wire:loading.attr="disabled" wire:target="sortBy" class="inline-flex items-center gap-1 hover:text-secondary-dark dark:hover:text-ink" aria-label="{{ __('strategies.sort_by_projects') }}">
                            {{ __('common.projects') }} <flux:icon :name="$sortIcon('projects')" :class="$sortIconClasses('projects')" />
                        </button>
                    </th>
                    <th scope="col" class="text-end!" aria-sort="{{ $sortAria('plans') ?? 'none' }}">
                        <button type="button" wire:click="sortBy('plans')" wire:loading.attr="disabled" wire:target="sortBy" class="inline-flex items-center gap-1 hover:text-secondary-dark dark:hover:text-ink" aria-label="{{ __('strategies.sort_by_annual_plans') }}">
                            {{ __('common.annual_plans') }} <flux:icon :name="$sortIcon('plans')" :class="$sortIconClasses('plans')" />
                        </button>
                    </th>
                    <th scope="col" class="min-w-40">{{ __('strategies.plan_coverage') }}</th>
                </x-slot:head>

                @foreach ($this->programs as $program)
                    @php
                        $rowYearsCovered = $this->planCoverage[$program->id] ?? 0;
                        $rowCoveragePct = $spanYears > 0 ? min(100, ($rowYearsCovered / $spanYears) * 100) : 0;
                        $rowCoverageTone = match (true) {
                            $rowCoveragePct >= 67 => 'good',
                            $rowCoveragePct >= 34 => 'funding',
                            default => 'warn',
                        };
                    @endphp
                    <tr wire:key="strategy-program-{{ $program->id }}">
                        <x-ui.data-table.cell :label="__('common.program')">
                            <div class="flex min-w-0 items-center gap-2.5">
                                <div class="flex size-8 shrink-0 items-center justify-center rounded-md bg-secondary-soft text-secondary-dark dark:bg-surface-2 dark:text-ink">
                                    <flux:icon.briefcase class="size-4" />
                                </div>
                                <a href="{{ route('programs.show', $program) }}" wire:navigate class="truncate font-semibold text-secondary-dark hover:underline dark:text-ink">
                                    {{ $program->name }}
                                </a>
                            </div>
                        </x-ui.data-table.cell>
                        <x-ui.data-table.cell :label="__('programs.manager')">
                            <x-ui.employee-chip :employee="$program->manager" compact />
                        </x-ui.data-table.cell>
                        <x-ui.data-table.cell :label="__('common.projects')" numeric>
                            <span class="inline-flex min-w-8 items-center justify-center rounded-md bg-secondary-soft px-2 py-1 text-xs font-semibold text-secondary-dark dark:bg-surface-2 dark:text-ink">{{ $program->projects_count }}</span>
                        </x-ui.data-table.cell>
                        <x-ui.data-table.cell :label="__('common.annual_plans')" numeric>
                            <span class="inline-flex min-w-8 items-center justify-center rounded-md border border-secondary-light px-2 py-1 text-xs font-semibold text-secondary-dark dark:text-ink">{{ $program->annual_plans_count }}</span>
                        </x-ui.data-table.cell>
                        <x-ui.data-table.cell :label="__('strategies.plan_coverage')">
                            <div class="min-w-28">
                                <x-ui.progress-bar
                                    :value="$rowYearsCovered"
                                    :max="$spanYears"
                                    :tone="$rowCoverageTone"
                                    :label="__('strategies.years_covered_fraction', ['covered' => $rowYearsCovered, 'total' => $spanYears])"
                                />
                            </div>
                        </x-ui.data-table.cell>
                    </tr>
                @endforeach
            </x-ui.data-table>
        @endif

        <x-slot:footer>
            <flux:pagination :paginator="$this->programs" class="text-secondary-medium! dark:text-ink-muted!" />
        </x-slot:footer>
    </x-ui.panel>
</section>
