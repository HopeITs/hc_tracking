        {{-- Layer two: the coverage matrix itself. --}}
        <section class="overflow-hidden rounded-xl border border-board-line bg-board" aria-labelledby="coverage-matrix-heading">
            <div class="flex min-h-12 flex-wrap items-center justify-between gap-3 border-b border-board-line bg-board-head px-5 py-3">
                <div class="min-w-0">
                    <h2 id="coverage-matrix-heading" class="text-base font-bold">{{ __('annual_plans.coverage_matrix') }}</h2>
                    <p class="mt-0.5 type-meta text-secondary-medium dark:text-ink-muted">{{ __('annual_plans.coverage_matrix_hint') }}</p>
                </div>

                @if ($activeYear !== null)
                    <button
                        type="button"
                        x-on:click="gapsOnly = ! gapsOnly"
                        x-bind:aria-pressed="gapsOnly ? 'true' : 'false'"
                        class="inline-flex min-h-9 shrink-0 cursor-pointer items-center gap-1.5 rounded-md border px-2.5 type-meta font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium"
                        x-bind:class="gapsOnly ? 'border-primary bg-primary dark:bg-ink dark:text-surface-0 dark:border-ink text-white' : 'border-board-line text-secondary-medium hover:text-secondary-dark dark:text-ink-muted dark:hover:text-ink'"
                    >
                        <flux:icon.funnel variant="micro" class="size-3.5" aria-hidden="true" />
                        {{ __('annual_plans.gaps_in_year', ['year' => $activeYear]) }}
                    </button>
                @endif
            </div>
            {{-- How to read a cell, stated before the grid rather than after it. --}}
            <div class="flex flex-wrap items-center gap-x-5 gap-y-2 border-b border-board-line bg-board-head px-5 py-2.5 type-meta text-secondary-medium dark:text-ink-muted">
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
                        <span class="absolute end-0 top-0 size-1.5 -translate-y-1/3 translate-x-1/3 rounded-full bg-primary dark:bg-status-critical rtl:translate-x-[-33%]"></span>
                    </span>
                    {{ __('annual_plans.legend_no_targets') }}
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <span aria-hidden="true" class="h-1.5 w-6 shrink-0 overflow-hidden rounded-full bg-secondary-soft dark:bg-surface-2"><span class="block h-full w-2/3 rounded-full bg-status-good"></span></span>
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
                        @php
                            $rowPlans = collect($yearColumns)->map(fn (int $year) => $this->planFor($program->id, $year))->filter();
                        @endphp
                        <article
                            @class([
                                'flex flex-col gap-3 px-4 py-4',
                                'bg-ground' => $loop->even,
                            ])
                            wire:key="mobile-program-{{ $program->id }}"
                            x-show="! gapsOnly || {{ $this->planFor($program->id, $activeYear ?? 0) === null ? 'true' : 'false' }}"
                        >
                            <div class="flex min-w-0 items-center gap-3">
                                <div class="grid size-10 shrink-0 place-items-center rounded-md bg-secondary-soft text-sm font-bold text-secondary-dark dark:bg-surface-2 dark:text-ink" aria-hidden="true">
                                    {{ mb_strtoupper(mb_substr($program->name, 0, 1)) }}
                                </div>
                                <div class="min-w-0 flex-1">
                                    <h3 class="truncate text-sm font-bold">{{ $program->name }}</h3>
                                    @if ($program->strategy)
                                        <span class="mt-0.5 inline-block max-w-full truncate rounded-full border border-board-line px-1.5 py-px type-meta text-secondary-medium dark:text-ink-muted">{{ $program->strategy->name }}</span>
                                    @else
                                        <p class="truncate type-meta text-secondary-light">{{ __('common.strategy_not_assigned') }}</p>
                                    @endif
                                </div>
                                <span class="ms-auto shrink-0 type-meta font-semibold tabular-nums text-secondary-medium dark:text-ink-muted">{{ $rowPlans->count() }}/{{ count($yearColumns) }}</span>
                            </div>

                            {{-- One full-width row per fiscal year: each carries its own edit and delete controls, which a two-column grid leaves no room for at phone widths. --}}
                            <ul class="grid gap-2">
                                @foreach ($yearColumns as $year)
                                    @php
                                        $plan = $this->planFor($program->id, $year);
                                    @endphp
                                    @php
                                        $stats = $plan === null ? null : ($delivery[$plan->id] ?? ['targets' => 0, 'attributed' => 0, 'reports' => 0, 'approved' => 0]);
                                    @endphp
                                    <li wire:key="mobile-cell-{{ $program->id }}-{{ $year }}">
                                        @if ($plan === null)
                                            @can('create', App\Models\AnnualPlan::class)<button
                                                type="button"
                                                wire:click="openCreateModal({{ $program->id }}, {{ $year }})"
                                                class="flex w-full min-h-11 touch-manipulation items-center justify-between gap-3 rounded-md border border-dashed border-board-line px-3 py-2 text-start transition-colors hover:border-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium"
                                                aria-label="{{ __('annual_plans.add_a_plan_for_program_in_year', ['program' => $program->name, 'year' => $year]) }}"
                                            >
                                                <span class="type-meta font-bold tabular-nums text-secondary-light">{{ $year }}</span>
                                                <span class="inline-flex items-center gap-1 type-meta text-secondary-medium dark:text-ink-muted">
                                                    <flux:icon.plus class="size-3.5" />
                                                    {{ __('annual_plans.add_plan') }}
                                                </span>
                                            </button>@endcan
                                        @else
                                            <div class="flex items-stretch gap-1.5">
                                            <a
                                                href="{{ route('annual-plans.show', $plan) }}"
                                                wire:navigate
                                                @class([
                                                    'flex min-w-0 flex-1 min-h-11 flex-col justify-center gap-1 rounded-md border px-3 py-2 transition-colors hover:border-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium',
                                                    'border-primary' => $year === $activeYear,
                                                    'border-board-line' => $year !== $activeYear,
                                                ])
                                                aria-label="{{ __('annual_plans.program_year_managed_by_manager_count_targets', ['program' => $program->name, 'year' => $year, 'manager' => $plan->manager?->name ?? __('common.not_assigned'), 'count' => $stats['targets']]) }}"
                                            >
                                                <span class="flex items-center justify-between gap-2">
                                                    <span class="type-meta font-bold tabular-nums">{{ $year }}</span>
                                                    <span class="inline-flex items-center gap-1.5">
                                                        @if ($stats['targets'] === 0)
                                                            <span aria-hidden="true" class="size-1.5 rounded-full bg-primary dark:bg-status-critical"></span>
                                                        @endif
                                                        <span class="text-sm font-bold tabular-nums">{{ $stats['targets'] }}</span>
                                                    </span>
                                                </span>
                                                <span class="truncate type-meta text-secondary-medium dark:text-ink-muted">{{ $plan->manager?->name ?? __('common.not_assigned') }}</span>
                                            </a>

                                                @can('update', $plan)<x-ui.button
                                                    tone="ghost"
                                                    size="sm"
                                                    icon="pencil-square"
                                                    class="size-11! shrink-0 touch-manipulation"
                                                    wire:click="openEditModal({{ $plan->id }})"
                                                    :aria-label="__('annual_plans.edit_the_plan_for_program_in_year', ['program' => $program->name, 'year' => $year])"
                                                />@endcan
                                                @can('delete', $plan)<x-ui.button
                                                    tone="ghost"
                                                    size="sm"
                                                    icon="trash"
                                                    class="size-11! shrink-0 touch-manipulation"
                                                    wire:click="confirmDelete({{ $plan->id }})"
                                                    :aria-label="__('annual_plans.delete_the_plan_for_program_in_year', ['program' => $program->name, 'year' => $year])"
                                                />@endcan
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
                                <span class="type-label text-secondary-medium dark:text-ink-muted">{{ __('common.program') }}</span>
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
                                            'flex w-full cursor-pointer flex-col items-center gap-1 rounded-md border-2 px-2 py-1.5 transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium',
                                            'border-primary' => $rail['isActive'],
                                            'border-transparent hover:border-board-line' => ! $rail['isActive'],
                                        ])
                                    >
                                        <span class="text-sm font-bold tabular-nums">{{ $rail['year'] }}</span>
                                        <span class="type-meta font-normal text-secondary-medium tabular-nums dark:text-ink-muted">({{ $rail['plans'] }})</span>
                                        <span class="h-1 w-full overflow-hidden rounded-full bg-board-line" aria-hidden="true">
                                            <span class="block h-full rounded-full bg-primary dark:bg-ink-muted" style="width: {{ $rail['coverage'] }}%"></span>
                                        </span>
                                    </button>
                                </div>
                            @endforeach
                        </div>

                        @foreach ($this->programRows as $program)
                            @php
                                $rowPlans = collect($yearColumns)->map(fn (int $year) => $this->planFor($program->id, $year))->filter();
                            @endphp
                            @php
                                $rowTargets = $rowPlans->sum(fn (\App\Models\AnnualPlan $plan): int => $delivery[$plan->id]['targets'] ?? 0);
                            @endphp
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
                                    <div class="grid size-9 shrink-0 place-items-center rounded-md bg-secondary-soft text-sm font-bold text-secondary-dark dark:bg-surface-2 dark:text-ink" aria-hidden="true">
                                        {{ mb_strtoupper(mb_substr($program->name, 0, 1)) }}
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-bold">{{ $program->name }}</p>
                                        @if ($program->strategy)
                                            <span class="mt-0.5 inline-block max-w-full truncate rounded-full border border-board-line px-1.5 py-px type-meta text-secondary-medium dark:text-ink-muted">{{ $program->strategy->name }}</span>
                                        @else
                                            <p class="truncate type-meta text-secondary-light">{{ __('common.strategy_not_assigned') }}</p>
                                        @endif
                                        <div class="mt-1.5 flex items-center gap-1" aria-hidden="true">
                                            @foreach ($yearColumns as $coverageYear)
                                                <span @class([
                                                    'size-1.5 shrink-0 rounded-full',
                                                    'bg-primary dark:bg-ink-muted' => $this->planFor($program->id, $coverageYear) !== null,
                                                    'bg-secondary-soft dark:bg-line-strong' => $this->planFor($program->id, $coverageYear) === null,
                                                ])></span>
                                            @endforeach
                                        </div>
                                    </div>
                                    <div class="shrink-0 text-end">
                                        <p class="text-sm font-bold tabular-nums">{{ $rowPlans->count() }}<span class="font-medium text-secondary-medium dark:text-ink-muted">/{{ count($yearColumns) }}</span></p>
                                        <p class="type-meta text-secondary-medium tabular-nums dark:text-ink-muted">{{ trans_choice('common.count_target', $rowTargets, ['count' => $rowTargets]) }}</p>
                                    </div>
                                </div>

                                @foreach ($yearColumns as $year)
                                    @php
                                        $plan = $this->planFor($program->id, $year);
                                    @endphp
                                    @php
                                        $stats = $plan === null ? null : ($delivery[$plan->id] ?? ['targets' => 0, 'attributed' => 0, 'reports' => 0, 'approved' => 0]);
                                    @endphp
                                    <div
                                        role="cell"
                                        class="group/cell relative p-1.5 transition-colors"
                                        wire:key="cell-{{ $program->id }}-{{ $year }}"
                                        x-bind:class="hoverYear === {{ $year }} ? 'bg-board-head' : ''"
                                    >
                                        @if ($plan === null)
                                            {{-- A gap is the one thing a coverage matrix exists to show, so it is also the fastest place to close it: the cell writes the plan it is missing. --}}
                                            @can('create', App\Models\AnnualPlan::class)<button
                                                type="button"
                                                wire:click="openCreateModal({{ $program->id }}, {{ $year }})"
                                                x-on:mouseenter="hoverYear = {{ $year }}"
                                                x-on:mouseleave="hoverYear = null"
                                                x-on:focus="hoverYear = {{ $year }}"
                                                x-on:blur="hoverYear = null"
                                                @class([
                                                    'group/gap flex h-full w-full min-h-14 items-center justify-center rounded-md border border-dashed transition-colors hover:border-primary hover:bg-board focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium',
                                                    'border-primary' => $year === $activeYear,
                                                    'border-secondary-soft dark:border-line' => $year !== $activeYear,
                                                ])
                                                aria-label="{{ __('annual_plans.add_a_plan_for_program_in_year', ['program' => $program->name, 'year' => $year]) }}"
                                            >
                                                <span aria-hidden="true" class="text-secondary-light group-hover/gap:hidden">—</span>
                                                <flux:icon.plus aria-hidden="true" class="hidden size-4 text-primary dark:text-brand-text group-hover/gap:block" />
                                            </button>@endcan
                                        @else
                                            @php
                                                $reportShare = $stats['reports'] > 0 ? (int) round($stats['approved'] / $stats['reports'] * 100) : 0;
                                            @endphp
                                            <a
                                                href="{{ route('annual-plans.show', $plan) }}"
                                                wire:navigate
                                                x-on:mouseenter="hoverYear = {{ $year }}"
                                                x-on:mouseleave="hoverYear = null"
                                                x-on:focus="hoverYear = {{ $year }}"
                                                x-on:blur="hoverYear = null"
                                                @class([
                                                    'relative flex h-full min-h-14 flex-col items-center justify-center gap-1.5 overflow-hidden rounded-md border bg-board transition-[transform,border-color,box-shadow] duration-150 hover:-translate-y-0.5 hover:border-primary hover:shadow-[0_10px_20px_-14px_rgb(0_0_0/0.55)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium motion-reduce:transition-none motion-reduce:hover:translate-y-0',
                                                    'border-primary' => $year === $activeYear,
                                                    'border-secondary-soft dark:border-line' => $year !== $activeYear,
                                                ])
                                                aria-label="{{ __('annual_plans.program_year_managed_by_manager_count_targets', ['program' => $program->name, 'year' => $year, 'manager' => $plan->manager?->name ?? __('common.not_assigned'), 'count' => $stats['targets']]) }}"
                                            >
                                                @if ($stats['targets'] === 0)
                                                    {{-- A flat primary dot flags an open plan with nothing committed yet; the number itself stays full-contrast ink in both themes. --}}
                                                    <span aria-hidden="true" class="absolute end-1.5 top-1.5 size-1.5 rounded-full bg-primary dark:bg-status-critical"></span>
                                                @endif
                                                <span class="text-xl font-bold leading-none tabular-nums text-secondary-dark dark:text-ink">{{ $stats['targets'] }}</span>

                                                @if ($stats['reports'] > 0)
                                                    <span class="h-1 w-8 overflow-hidden rounded-full bg-secondary-soft dark:bg-surface-2" aria-hidden="true">
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
                                                @can('update', $plan)<button
                                                    type="button"
                                                    wire:click="openEditModal({{ $plan->id }})"
                                                    class="grid size-5 place-items-center rounded border border-secondary-soft bg-board text-secondary-medium transition-colors hover:border-primary hover:text-primary dark:hover:text-brand-text focus-visible:opacity-100 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-secondary-medium dark:border-line dark:text-ink-muted"
                                                    aria-label="{{ __('annual_plans.edit_the_plan_for_program_in_year', ['program' => $program->name, 'year' => $year]) }}"
                                                >
                                                    <flux:icon.pencil-square class="size-3" />
                                                </button>@endcan
                                                @can('delete', $plan)<button
                                                    type="button"
                                                    wire:click="confirmDelete({{ $plan->id }})"
                                                    class="grid size-5 place-items-center rounded border border-secondary-soft bg-board text-secondary-medium transition-colors hover:border-primary hover:text-primary dark:hover:text-brand-text focus-visible:opacity-100 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-secondary-medium dark:border-line dark:text-ink-muted"
                                                    aria-label="{{ __('annual_plans.delete_the_plan_for_program_in_year', ['program' => $program->name, 'year' => $year]) }}"
                                                >
                                                    <flux:icon.trash class="size-3" />
                                                </button>@endcan
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="border-t border-board-line px-4 pb-3">
                    <flux:pagination :paginator="$this->programRows" class="text-secondary-medium! [&_button]:text-secondary-medium! [&_button:hover]:bg-secondary-soft! dark:text-ink-muted! dark:[&_button]:text-ink-muted! dark:[&_button:hover]:bg-surface-2!" scroll-to="#annual-plan-matrix-heading" />
                </div>
            @endif
        </section>
