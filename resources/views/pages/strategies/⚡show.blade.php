<?php

use App\Models\Program;
use App\Models\Strategy;
use App\Models\YearlyPlan;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('titles.strategy')] class extends Component {
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
    }

    #[Computed]
    public function strategy(): Strategy
    {
        return Strategy::query()
            ->select(['id', 'name', 'start_year', 'end_year', 'updated_at'])
            ->withCount('programs')
            ->findOrFail($this->strategyId);
    }

    /** @return Collection<int, Program> */
    #[Computed]
    public function programs(): Collection
    {
        $programs = Program::query()
            ->select(['id', 'name', 'strategy_id', 'manager_id'])
            ->where('strategy_id', $this->strategyId)
            ->with(['manager:id,first_name_en,last_name_en,first_name_ar,last_name_ar'])
            ->withCount(['projects', 'yearlyPlans'])
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $direction = $this->direction === 'desc' ? 'desc' : 'asc';

        return match ($this->sort) {
            'projects' => $programs->sortBy('projects_count', descending: $direction === 'desc')->values(),
            'plans' => $programs->sortBy('yearly_plans_count', descending: $direction === 'desc')->values(),
            default => $programs->sortBy('name', descending: $direction === 'desc')->values(),
        };
    }

    /**
     * Distinct plan-years recorded per program, within this strategy's span.
     *
     * The (program_id, year) unique constraint on yearly_plans makes a plain
     * row count already a distinct-year count, no DISTINCT needed.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function planCoverage(): array
    {
        $strategy = $this->strategy;
        $programIds = $this->programs->pluck('id');

        if ($programIds->isEmpty()) {
            return [];
        }

        return YearlyPlan::query()
            ->whereIn('program_id', $programIds)
            ->whereBetween('year', [$strategy->start_year, $strategy->end_year])
            ->select('program_id', DB::raw('COUNT(*) as years_covered'))
            ->groupBy('program_id')
            ->pluck('years_covered', 'program_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    #[Computed]
    public function totals(): array
    {
        return [
            'projects' => (int) $this->programs->sum('projects_count'),
            'plans' => (int) $this->programs->sum('yearly_plans_count'),
        ];
    }

    /**
     * Share of every program's span-years that carry an annual plan, averaged
     * across the whole portfolio. Null when there are no programs to cover.
     */
    #[Computed]
    public function averageCoverage(): ?float
    {
        if ($this->programs->isEmpty()) {
            return null;
        }

        $spanYears = $this->strategy->end_year - $this->strategy->start_year + 1;

        if ($spanYears <= 0) {
            return null;
        }

        $possible = $this->programs->count() * $spanYears;
        $covered = array_sum($this->planCoverage);

        return min(100, ($covered / $possible) * 100);
    }
};
?>

@php
    $strategy = $this->strategy;
    $currentYear = now()->year;
    $spanYears = $strategy->end_year - $strategy->start_year + 1;
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
        'active' => 'bg-status-good text-white',
        'upcoming' => 'bg-secondary-dark text-white dark:bg-white dark:text-secondary-dark',
        default => 'bg-secondary-soft text-secondary-dark dark:bg-secondary-medium dark:text-white',
    };

    $sortAria = fn (string $column): ?string => $sort === $column ? ($direction === 'asc' ? 'ascending' : 'descending') : null;
@endphp

<section class="@container/detail flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white">
    <x-ui.detail-header
        :breadcrumbs="[
            ['label' => __('common.strategies'), 'url' => route('strategies.index')],
            ['label' => $strategy->name],
        ]"
        :title="$strategy->name"
        :subtitle="__('strategies.strategic_period_start_to_end', ['start' => $strategy->start_year, 'end' => $strategy->end_year])"
    >
        <x-slot:meta>
            <span class="{{ $statusChipClasses }} inline-flex shrink-0 items-center rounded-md px-2.5 py-1 text-xs font-semibold">{{ $statusLabel }}</span>
            <span>{{ trans_choice('strategies.count_program', $strategy->programs_count, ['count' => $strategy->programs_count]) }}</span>
            <span>{{ __('common.updated_date', ['date' => $strategy->updated_at?->translatedFormat(__('formats.date'))]) }}</span>
            @if ($yearOfSpan !== null)
                <span>{{ __('strategies.year_of_total', ['current' => $yearOfSpan, 'total' => $spanYears]) }}</span>
            @endif
        </x-slot:meta>
    </x-ui.detail-header>

    @php
        $coverageTone = match (true) {
            $this->averageCoverage === null => null,
            $this->averageCoverage >= 67 => 'good',
            $this->averageCoverage >= 34 => 'funding',
            default => 'warn',
        };

        $coverageIconClasses = match ($coverageTone) {
            'good' => 'bg-status-good-soft text-status-good',
            'warn' => 'bg-status-warn-soft text-status-warn',
            'funding' => 'bg-funding-soft text-funding',
            default => 'bg-secondary-soft text-secondary-dark dark:bg-secondary-medium dark:text-white',
        };
    @endphp

    <div class="grid gap-3 @2xl/detail:grid-cols-4">
        <x-ui.stat-tile :label="__('common.programs')" :value="$strategy->programs_count" icon="briefcase" :href="route('programs.index', ['strategy' => $strategy->id])" />
        <x-ui.stat-tile :label="__('common.projects')" :value="$this->totals['projects']" icon="rectangle-stack" :href="route('programs.index', ['strategy' => $strategy->id])" />
        <x-ui.stat-tile :label="__('common.annual_plans')" :value="$this->totals['plans']" icon="calendar-days" :href="route('programs.index', ['strategy' => $strategy->id])" />

        <div class="rounded-lg border border-secondary-light bg-white p-3.5 dark:border-secondary-medium dark:bg-secondary-dark">
            <div class="flex items-start justify-between gap-3">
                <p class="type-label text-secondary-medium dark:text-secondary-soft">{{ __('strategies.plan_coverage') }}</p>
                <div class="{{ $coverageIconClasses }} flex size-9 shrink-0 items-center justify-center rounded-md">
                    <flux:icon.chart-bar class="size-4.5" />
                </div>
            </div>
            <div class="mt-3">
                <x-ui.progress-bar :value="$this->averageCoverage" :tone="$coverageTone ?? 'neutral'" show-value />
            </div>
        </div>
    </div>

    <x-ui.panel :title="__('common.programs')" :count="$this->programs->count()" :subtitle="__('strategies.delivery_programs_carried_by_this_strategy')">
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
                            ? 'size-3.5 text-secondary-light dark:text-secondary-medium'
                            : 'size-3.5 text-primary';
                    @endphp

                    <th scope="col" aria-sort="{{ $sortAria('name') ?? 'none' }}">
                        <button type="button" wire:click="sortBy('name')" wire:loading.attr="disabled" wire:target="sortBy" class="inline-flex items-center gap-1 hover:text-secondary-dark dark:hover:text-white" aria-label="{{ __('strategies.sort_by_name') }}">
                            {{ __('common.program') }} <flux:icon :name="$sortIcon('name')" :class="$sortIconClasses('name')" />
                        </button>
                    </th>
                    <th scope="col">{{ __('programs.manager') }}</th>
                    <th scope="col" class="text-end!" aria-sort="{{ $sortAria('projects') ?? 'none' }}">
                        <button type="button" wire:click="sortBy('projects')" wire:loading.attr="disabled" wire:target="sortBy" class="inline-flex items-center gap-1 hover:text-secondary-dark dark:hover:text-white" aria-label="{{ __('strategies.sort_by_projects') }}">
                            {{ __('common.projects') }} <flux:icon :name="$sortIcon('projects')" :class="$sortIconClasses('projects')" />
                        </button>
                    </th>
                    <th scope="col" class="text-end!" aria-sort="{{ $sortAria('plans') ?? 'none' }}">
                        <button type="button" wire:click="sortBy('plans')" wire:loading.attr="disabled" wire:target="sortBy" class="inline-flex items-center gap-1 hover:text-secondary-dark dark:hover:text-white" aria-label="{{ __('strategies.sort_by_annual_plans') }}">
                            {{ __('common.annual_plans') }} <flux:icon :name="$sortIcon('plans')" :class="$sortIconClasses('plans')" />
                        </button>
                    </th>
                    <th scope="col" class="min-w-40">{{ __('strategies.plan_coverage') }}</th>
                </x-slot:head>

                @foreach ($this->programs as $program)
                    @php
                        $yearsCovered = $this->planCoverage[$program->id] ?? 0;
                        $coveragePct = $spanYears > 0 ? min(100, ($yearsCovered / $spanYears) * 100) : 0;
                        $rowCoverageTone = match (true) {
                            $coveragePct >= 67 => 'good',
                            $coveragePct >= 34 => 'funding',
                            default => 'warn',
                        };
                    @endphp
                    <tr wire:key="strategy-program-{{ $program->id }}">
                        <x-ui.data-table.cell :label="__('common.program')">
                            <div class="flex min-w-0 items-center gap-2.5">
                                <div class="flex size-8 shrink-0 items-center justify-center rounded-md bg-secondary-soft text-secondary-dark dark:bg-secondary-medium dark:text-white">
                                    <flux:icon.briefcase class="size-4" />
                                </div>
                                @if (Route::has('programs.show'))
                                    <a href="{{ route('programs.show', $program) }}" wire:navigate class="truncate font-semibold text-secondary-dark hover:underline dark:text-white">
                                        {{ $program->name }}
                                    </a>
                                @else
                                    <p class="truncate font-semibold text-secondary-dark dark:text-white">{{ $program->name }}</p>
                                @endif
                            </div>
                        </x-ui.data-table.cell>
                        <x-ui.data-table.cell :label="__('programs.manager')">
                            <x-ui.employee-chip :employee="$program->manager" compact />
                        </x-ui.data-table.cell>
                        <x-ui.data-table.cell :label="__('common.projects')" numeric>
                            <span class="inline-flex min-w-8 items-center justify-center rounded-md bg-secondary-soft px-2 py-1 text-xs font-semibold text-secondary-dark dark:bg-secondary-medium dark:text-white">{{ $program->projects_count }}</span>
                        </x-ui.data-table.cell>
                        <x-ui.data-table.cell :label="__('common.annual_plans')" numeric>
                            <span class="inline-flex min-w-8 items-center justify-center rounded-md border border-secondary-light px-2 py-1 text-xs font-semibold text-secondary-dark dark:text-white">{{ $program->yearly_plans_count }}</span>
                        </x-ui.data-table.cell>
                        <x-ui.data-table.cell :label="__('strategies.plan_coverage')">
                            <x-ui.progress-bar :value="$coveragePct" :tone="$rowCoverageTone" show-value />
                        </x-ui.data-table.cell>
                    </tr>
                @endforeach
            </x-ui.data-table>
        @endif
    </x-ui.panel>
</section>
