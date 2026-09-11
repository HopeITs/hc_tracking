<?php

use App\Models\Program;
use App\Models\User;
use App\Models\YearlyPlan;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Annual Plans')] class extends Component {
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

    public function clearFilters(): void
    {
        $this->yearFrom = '';
        $this->yearTo = '';
        $this->manager = '';
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
        return YearlyPlan::query()
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
     * The managers accountable for at least one annual plan.
     *
     * Users without a plan are left out, so the filter cannot be set to a value
     * that empties the grid.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function managerOptions(): Collection
    {
        return User::query()
            ->select(['id', 'name', 'email', 'role'])
            ->whereHas('managedYearlyPlans')
            ->orderBy('name')
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
        $range = $this->yearRange;
        $managerId = $this->selectedManagerId();

        return Program::query()
            ->select(['id', 'name', 'strategy_id'])
            ->with(['strategy:id,name,start_year,end_year'])
            ->when($managerId !== null, fn (Builder $query) => $query->whereHas(
                'yearlyPlans',
                function (Builder $query) use ($managerId, $range): void {
                    $query->managedBy($managerId);

                    if ($range !== null) {
                        $query->forYears($range['from'], $range['to']);
                    }
                },
            ))
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
     * @return array<string, YearlyPlan>
     */
    #[Computed]
    public function planCells(): array
    {
        $range = $this->yearRange;
        $programIds = $this->programRows->getCollection()->modelKeys();

        if ($range === null || $programIds === []) {
            return [];
        }

        return YearlyPlan::query()
            ->select(['id', 'program_id', 'manager_id', 'year'])
            ->whereIn('program_id', $programIds)
            ->forYears($range['from'], $range['to'])
            ->managedBy($this->selectedManagerId())
            ->with('manager:id,name,email,role')
            ->withCount('planIndicators')
            ->get()
            ->keyBy(fn (YearlyPlan $plan): string => $plan->program_id.':'.$plan->year)
            ->all();
    }

    /**
     * How many plans each visible fiscal year holds across the filtered set.
     *
     * Counted in the database rather than from the cells, so the figure covers
     * every page of programs and not just the one on screen.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function columnTotals(): array
    {
        $range = $this->yearRange;

        if ($range === null) {
            return [];
        }

        return YearlyPlan::query()
            ->selectRaw('year, count(*) as plans_total')
            ->forYears($range['from'], $range['to'])
            ->managedBy($this->selectedManagerId())
            ->groupBy('year')
            ->pluck('plans_total', 'year')
            ->map(fn ($total): int => (int) $total)
            ->all();
    }

    /**
     * The plan of one program in one fiscal year, or null where none exists.
     */
    public function planFor(int $programId, int $year): ?YearlyPlan
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
    $controlClasses = 'border-secondary-light! bg-white! text-secondary-dark! placeholder:text-secondary-light! dark:border-secondary-light! dark:bg-secondary-medium! dark:text-white!';
    $secondaryButtonClasses = 'border-secondary-light! bg-white! text-secondary-dark! hover:bg-secondary-soft! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white! dark:hover:bg-secondary-medium!';
    $stickyCellClasses = 'sticky left-0 z-10 bg-white group-hover:bg-secondary-soft dark:bg-secondary-dark dark:group-hover:bg-secondary-medium';
    $yearColumns = $this->yearColumns;
    $yearRange = $this->yearRange;
    $columnTotals = $this->columnTotals;
    $filtersAreActive = $yearFrom !== '' || $yearTo !== '' || $manager !== '';
@endphp

<section
    class="admin-directory @container/matrix flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white [&_[data-flux-cell]]:border-secondary-soft! [&_[data-flux-cell]]:text-secondary-medium! [&_[data-flux-column]]:border-secondary-soft! [&_[data-flux-column]]:text-secondary-dark! [&_[data-flux-heading]]:text-secondary-dark! [&_[data-flux-label]]:text-secondary-dark! [&_[data-flux-subheading]]:text-secondary-medium! dark:[&_[data-flux-cell]]:border-secondary-medium! dark:[&_[data-flux-cell]]:text-secondary-soft! dark:[&_[data-flux-column]]:border-secondary-medium! dark:[&_[data-flux-column]]:text-white! dark:[&_[data-flux-heading]]:text-white! dark:[&_[data-flux-label]]:text-white! dark:[&_[data-flux-subheading]]:text-secondary-soft!"
    aria-labelledby="yearly-plan-matrix-heading"
>
    <header class="flex flex-col gap-3 border-b border-secondary-soft pb-4 dark:border-secondary-medium @6xl/matrix:flex-row @6xl/matrix:items-end">
        <div class="min-w-48 @6xl/matrix:me-auto">
            <div class="flex items-center gap-2">
                <flux:heading id="yearly-plan-matrix-heading" size="lg">{{ __('Annual Plans') }}</flux:heading>
                <span class="inline-flex min-w-7 items-center justify-center rounded-md border border-secondary-light px-2 py-0.5 text-xs font-semibold text-secondary-medium dark:text-secondary-soft">
                    {{ $this->programRows->total() }}
                </span>
            </div>
            <p class="text-sm text-secondary-medium dark:text-secondary-soft">{{ __('Annual plan coverage across every program, fiscal year by fiscal year.') }}</p>
        </div>

        <div class="grid w-full gap-2 @sm/matrix:grid-cols-2 @3xl/matrix:grid-cols-[minmax(8rem,0.6fr)_minmax(8rem,0.6fr)_minmax(11rem,1fr)] @6xl/matrix:max-w-[46rem]">
            <flux:select :label="__('From fiscal year')" :class="$controlClasses" wire:model.live="yearFrom">
                <flux:select.option value="">{{ __('Earliest') }}</flux:select.option>
                @foreach ($this->yearOptions as $yearOption)
                    <flux:select.option :value="$yearOption" :wire:key="'year-from-'.$yearOption">{{ $yearOption }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select :label="__('To fiscal year')" :class="$controlClasses" wire:model.live="yearTo">
                <flux:select.option value="">{{ __('Latest') }}</flux:select.option>
                @foreach ($this->yearOptions as $yearOption)
                    <flux:select.option :value="$yearOption" :wire:key="'year-to-'.$yearOption">{{ $yearOption }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select :label="__('Assigned manager')" :class="$controlClasses" wire:model.live="manager">
                <flux:select.option value="">{{ __('All managers') }}</flux:select.option>
                @foreach ($this->managerOptions as $managerOption)
                    <flux:select.option :value="$managerOption->id" :wire:key="'manager-filter-'.$managerOption->id">
                        {{ $managerOption->name }} · {{ $managerOption->role->value }}
                    </flux:select.option>
                @endforeach
            </flux:select>
        </div>
    </header>

    <div class="overflow-hidden rounded-lg border border-secondary-light bg-white dark:border-secondary-medium dark:bg-secondary-dark">
        <div class="flex min-h-10 flex-wrap items-center justify-between gap-2 border-b border-secondary-soft px-3.5 py-2 dark:border-secondary-medium">
            <p class="text-xs font-semibold text-secondary-medium dark:text-secondary-soft">
                @if ($yearRange === null)
                    {{ __('No fiscal years to show') }}
                @else
                    {{ __('Fiscal years :from–:to', ['from' => $yearRange['from'], 'to' => $yearRange['to']]) }}
                    @if ($yearRange['clamped'])
                        <span class="font-normal">· {{ __('narrowed to :count columns', ['count' => count($yearColumns)]) }}</span>
                    @endif
                @endif
            </p>
            <div class="flex items-center gap-2">
                <span wire:loading.delay.flex wire:target="yearFrom,yearTo,manager,clearFilters" class="hidden items-center gap-1.5 text-xs text-secondary-medium dark:text-secondary-soft">
                    <flux:icon.arrow-path class="size-3.5 animate-spin" /> {{ __('Updating') }}
                </span>
                @if ($filtersAreActive)
                    <flux:button size="sm" variant="ghost" class="text-secondary-medium! hover:bg-secondary-soft! hover:text-secondary-dark! dark:text-secondary-soft! dark:hover:bg-secondary-medium! dark:hover:text-white!" wire:click="clearFilters">
                        {{ __('Reset filters') }}
                    </flux:button>
                @endif
            </div>
        </div>

        @if ($yearColumns === [])
            <div class="flex min-h-64 flex-col items-center justify-center gap-3 px-5 py-10 text-center">
                <div class="flex size-12 items-center justify-center rounded-lg border border-secondary-light bg-white text-secondary-medium dark:bg-secondary-dark dark:text-secondary-soft">
                    <flux:icon.calendar-days class="size-5" />
                </div>
                <div class="max-w-sm space-y-1">
                    <flux:heading size="base">{{ __('No annual plans yet') }}</flux:heading>
                    <p class="text-sm text-secondary-medium dark:text-secondary-soft">{{ __('Once a program has an annual plan, its fiscal years appear here as columns.') }}</p>
                </div>
            </div>
        @elseif ($this->programRows->isEmpty())
            <div class="flex min-h-64 flex-col items-center justify-center gap-3 px-5 py-10 text-center">
                <div class="flex size-12 items-center justify-center rounded-lg border border-secondary-light bg-white text-secondary-medium dark:bg-secondary-dark dark:text-secondary-soft">
                    <flux:icon.calendar-days class="size-5" />
                </div>
                <div class="max-w-sm space-y-1">
                    <flux:heading size="base">{{ $filtersAreActive ? __('No matching annual plans') : __('No programs yet') }}</flux:heading>
                    <p class="text-sm text-secondary-medium dark:text-secondary-soft">
                        {{ $filtersAreActive ? __('Change the fiscal years or the manager and try again.') : __('Add a program before planning its year.') }}
                    </p>
                </div>
                @if ($filtersAreActive)
                    <flux:button size="sm" :class="$secondaryButtonClasses" wire:click="clearFilters">{{ __('Reset filters') }}</flux:button>
                @endif
            </div>
        @else
            <div class="divide-y divide-secondary-soft dark:divide-secondary-medium @5xl/matrix:hidden">
                @foreach ($this->programRows as $program)
                    <article class="flex flex-col gap-3 p-3.5" wire:key="mobile-program-{{ $program->id }}">
                        <div class="flex min-w-0 items-center gap-3">
                            <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-secondary-soft text-secondary-dark dark:bg-secondary-medium dark:text-white">
                                <flux:icon.briefcase class="size-4.5" />
                            </div>
                            <div class="min-w-0">
                                <h2 class="truncate text-sm font-semibold text-secondary-dark dark:text-white">{{ $program->name }}</h2>
                                <p class="truncate text-xs text-secondary-medium dark:text-secondary-soft">{{ $program->strategy->name }}</p>
                            </div>
                        </div>

                        <ul class="flex flex-col gap-2">
                            @foreach ($yearColumns as $year)
                                @php($plan = $this->planFor($program->id, $year))
                                <li class="flex min-h-11 items-center justify-between gap-3 rounded-lg border border-secondary-soft px-2.5 py-2 dark:border-secondary-medium" wire:key="mobile-cell-{{ $program->id }}-{{ $year }}">
                                    <span class="text-xs font-semibold tabular-nums text-secondary-dark dark:text-white">{{ $year }}</span>
                                    @if ($plan === null)
                                        <span class="text-xs text-secondary-medium dark:text-secondary-soft">{{ __('No plan') }}</span>
                                    @else
                                        <a href="{{ route('yearly-plans.show', $plan) }}" wire:navigate class="inline-flex min-h-11 items-center justify-center rounded-md px-2 hover:underline sm:min-h-8">
                                            <span class="flex min-w-0 items-center gap-2">
                                                <flux:avatar class="bg-secondary-soft! text-secondary-dark! dark:bg-secondary-medium! dark:text-white!" size="xs" :initials="$plan->manager->initials()" />
                                                <span class="truncate text-xs font-medium text-secondary-dark dark:text-white">{{ $plan->manager->name }}</span>
                                                <span class="inline-flex min-w-6 shrink-0 items-center justify-center rounded-md border border-secondary-light px-1.5 py-0.5 text-xs font-semibold tabular-nums text-secondary-dark dark:text-white">
                                                    {{ $plan->plan_indicators_count }}
                                                </span>
                                            </span>
                                        </a>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </article>
                @endforeach
            </div>

            <div class="hidden overflow-x-auto @5xl/matrix:block">
                <flux:table class="[&_[data-flux-cell]]:px-3.5! [&_[data-flux-cell]]:py-2.5! [&_[data-flux-column]]:px-3.5! [&_[data-flux-column]]:py-2.5! [&_tbody_tr]:transition-colors [&_tbody_tr]:hover:bg-secondary-soft dark:[&_tbody_tr]:hover:bg-secondary-medium">
                    <flux:table.columns class="bg-secondary-soft text-xs font-semibold dark:bg-secondary-medium">
                        <flux:table.column class="sticky left-0 z-20 min-w-56 bg-secondary-soft dark:bg-secondary-medium">{{ __('Program') }}</flux:table.column>
                        @foreach ($yearColumns as $year)
                            <flux:table.column class="min-w-28" align="center" :key="'year-column-'.$year">
                                <span class="tabular-nums">{{ $year }}</span>
                                <span class="ms-1 font-normal text-secondary-medium dark:text-secondary-soft">({{ $columnTotals[$year] ?? 0 }})</span>
                            </flux:table.column>
                        @endforeach
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($this->programRows as $program)
                            <flux:table.row :key="$program->id" class="group">
                                <flux:table.cell variant="strong" :class="$stickyCellClasses.' text-secondary-dark! dark:text-white!'">
                                    <div class="flex min-w-0 items-center gap-2.5">
                                        <div class="flex size-8 shrink-0 items-center justify-center rounded-md bg-secondary-soft text-secondary-dark dark:bg-secondary-medium dark:text-white"><flux:icon.briefcase class="size-4" /></div>
                                        <div class="min-w-0">
                                            <p class="truncate font-semibold">{{ $program->name }}</p>
                                            <p class="truncate text-xs font-normal text-secondary-medium dark:text-secondary-soft">{{ $program->strategy->name }}</p>
                                        </div>
                                    </div>
                                </flux:table.cell>
                                @foreach ($yearColumns as $year)
                                    @php($plan = $this->planFor($program->id, $year))
                                    <flux:table.cell align="center" :key="'cell-'.$program->id.'-'.$year">
                                        @if ($plan === null)
                                            <span class="text-secondary-light" aria-label="{{ __(':program, :year, no plan', ['program' => $program->name, 'year' => $year]) }}">—</span>
                                        @else
                                            <a
                                                href="{{ route('yearly-plans.show', $plan) }}"
                                                wire:navigate
                                                class="inline-flex min-h-11 items-center justify-center gap-1.5 rounded-md px-2 hover:underline sm:min-h-8"
                                                aria-label="{{ __(':program, :year, managed by :manager, :count targets', ['program' => $program->name, 'year' => $year, 'manager' => $plan->manager->name, 'count' => $plan->plan_indicators_count]) }}"
                                            >
                                                <flux:avatar class="bg-secondary-soft! text-secondary-dark! dark:bg-secondary-medium! dark:text-white!" size="xs" :initials="$plan->manager->initials()" />
                                                <span class="inline-flex min-w-6 items-center justify-center rounded-md border border-secondary-light px-1.5 py-0.5 text-xs font-semibold tabular-nums text-secondary-dark dark:text-white">
                                                    {{ $plan->plan_indicators_count }}
                                                </span>
                                            </a>
                                        @endif
                                    </flux:table.cell>
                                @endforeach
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>

            <div class="border-t border-secondary-soft px-3 pb-3 dark:border-secondary-medium">
                <flux:pagination :paginator="$this->programRows" class="text-secondary-medium! [&_button]:text-secondary-medium! [&_button:hover]:bg-secondary-soft! dark:text-secondary-soft! dark:[&_button]:text-secondary-soft! dark:[&_button:hover]:bg-secondary-medium!" scroll-to="#yearly-plan-matrix-heading" />
            </div>
        @endif
    </div>
</section>
