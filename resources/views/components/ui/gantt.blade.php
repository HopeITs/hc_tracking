@props([
    'payload',
    'scope' => 'default',
    'editable' => false,
    'height' => 'h-[min(72vh,46rem)]',
    'explorer' => false,
    'groupBy' => null,
    'groupOptions' => [],
])

{{--
    The Gantt engine's shell. All layout and interaction lives in the
    ganttChart Alpine component (resources/js/gantt/index.js); this view only
    lays out the toolbar, split grid/timeline, link layer and popovers it
    binds to.

    Pass `editable` only where the Livewire page mixes in EditsProjectGantt —
    every edit calls one of its gantt* actions through $wire. Wrap the
    component in wire:ignore (the chart redraws from each action's answer).

    Pass `explorer` for the portfolio-wide Timeline: it adds the signal line,
    the horizon band, route tracing and the inspector. `groupOptions` (with
    the current `groupBy`) adds a group-by picker that sets the page's
    Livewire `group` property.

    The chart area and the horizon band are forced LTR: bars are positioned by
    pixel offset from the window start, and time reads left-to-right in both
    locales, as MS Project does. Row text keeps dir="auto" so Arabic names
    still read correctly.
--}}

@php
    $tones = [
        'bar' => [
            'neutral' => 'bg-secondary-medium dark:bg-ink-muted',
            'good' => 'bg-status-good',
            'warn' => 'bg-status-warn',
            'critical' => 'bg-status-critical',
            'funding' => 'bg-funding',
            'muted' => 'bg-secondary-light/70 dark:bg-ink-faint',
        ],
        'track' => [
            'neutral' => 'bg-secondary-soft/70 dark:bg-white/15',
            'good' => 'bg-status-good-soft',
            'warn' => 'bg-status-warn-soft',
            'critical' => 'bg-status-critical-soft',
            'funding' => 'bg-funding-soft',
            'muted' => 'bg-secondary-soft/40 dark:bg-white/10',
        ],
        'edge' => [
            'neutral' => 'ring-secondary-light/45 dark:ring-white/25',
            'good' => 'ring-status-good/40',
            'warn' => 'ring-status-warn/45',
            'critical' => 'ring-status-critical/50',
            'funding' => 'ring-funding/45',
            'muted' => 'ring-secondary-soft dark:ring-white/15',
        ],
        'pill' => [
            'neutral' => 'bg-board-head text-secondary-dark ring-secondary-soft dark:bg-white/10 dark:text-ink dark:ring-white/15',
            'good' => 'bg-status-good-soft text-status-good ring-status-good/25',
            'warn' => 'bg-status-warn-soft text-status-warn ring-status-warn/25',
            'critical' => 'bg-status-critical-soft text-status-critical ring-status-critical/25',
            'funding' => 'bg-funding-soft text-funding ring-funding/25',
            'muted' => 'bg-transparent text-secondary-light ring-secondary-soft dark:text-ink-muted dark:ring-white/15',
        ],
    ];
    $icons = [
        'folder' => ['folder', 'text-secondary-medium dark:text-ink-muted'],
        'money' => ['currency-dollar', 'text-funding'],
        'calendar' => ['calendar-days', 'text-funding'],
        'project' => ['briefcase', 'text-secondary-dark dark:text-ink'],
        'milestone' => ['flag', 'text-secondary-medium dark:text-ink-muted'],
        'task' => ['check-circle', 'text-secondary-light'],
        'proposal' => ['document-text', 'text-funding'],
        'transfer' => ['banknotes', 'text-funding'],
        'report' => ['document-chart-bar', 'text-funding'],
    ];
    $zoomLevels = [
        'day' => __('gantt.zoom_levels.day'),
        'week' => __('gantt.zoom_levels.week'),
        'month' => __('gantt.zoom_levels.month'),
        'quarter' => __('gantt.zoom_levels.quarter'),
        'year' => __('gantt.zoom_levels.year'),
    ];
    $weekendPresets = [
        'sat-sun' => __('gantt.weekend_presets.sat-sun'),
        'fri-sat' => __('gantt.weekend_presets.fri-sat'),
        'fri' => __('gantt.weekend_presets.fri'),
        'sun' => __('gantt.weekend_presets.sun'),
        'none' => __('gantt.weekend_presets.none'),
    ];
    $columns = [
        'status' => __('gantt.columns.status'),
        'owner' => __('gantt.columns.owner'),
        'start' => __('gantt.start'),
        'finish' => __('gantt.finish'),
        'duration' => __('gantt.columns.duration'),
        'progress' => __('gantt.columns.progress'),
        'predecessors' => __('gantt.predecessors'),
        'float' => __('gantt.total_float'),
    ];
    $shortcuts = [
        '/' => __('gantt.shortcuts.search'),
        'T' => __('gantt.shortcuts.today'),
        'F' => __('gantt.shortcuts.fit'),
        '1 – 5' => __('gantt.shortcuts.zoom_levels'),
        '+ / −' => __('gantt.shortcuts.zoom'),
        '↑ ↓' => __('gantt.shortcuts.move'),
        '← →' => __('gantt.shortcuts.fold'),
        'Enter' => __('gantt.shortcuts.open'),
        'Space' => __('gantt.shortcuts.scroll_to'),
        'L' => __('gantt.shortcuts.links'),
        'C' => __('gantt.shortcuts.critical'),
    ];
    if ($explorer) {
        $shortcuts['R'] = __('gantt.shortcuts.trace');
        $shortcuts['Esc'] = __('gantt.shortcuts.clear');
    }
    $linkTypes = collect(\App\Enums\DependencyType::cases())->mapWithKeys(fn ($type) => [$type->value => $type->code().' · '.$type->label()]);
    $labels = [
        'confirmDeleteBaseline' => __('gantt.confirm_delete_baseline'),
        'kinds' => [
            'group' => __('gantt.kinds.group'),
            'proposal' => __('gantt.kinds.proposal'),
            'milestone' => __('gantt.kinds.milestone'),
            'task' => __('gantt.kinds.task'),
            'transfer' => __('gantt.kinds.transfer'),
            'report' => __('gantt.kinds.report'),
            'project' => __('gantt.kinds.project'),
        ],
        'roles' => [
            'up' => __('gantt.roles.up'),
            'down' => __('gantt.roles.down'),
            'funds' => __('gantt.roles.funds'),
            'cofunded' => __('gantt.roles.cofunded'),
            'obligation' => __('gantt.roles.obligation'),
        ],
        'csv' => [
            'wbs' => __('gantt.wbs'),
            'name' => __('gantt.task_name'),
            'kind' => __('gantt.csv.kind'),
            'start' => __('gantt.start'),
            'finish' => __('gantt.finish'),
            'duration' => __('gantt.csv.duration'),
            'progress' => __('gantt.csv.progress'),
            'predecessors' => __('gantt.predecessors'),
            'owner' => __('gantt.owner'),
            'status' => __('gantt.csv.status'),
            'float' => __('gantt.total_float'),
            'critical' => __('gantt.critical'),
            'baselineStart' => __('gantt.csv.baseline_start'),
            'baselineFinish' => __('gantt.csv.baseline_finish'),
            'variance' => __('gantt.variance'),
            'money' => __('gantt.csv.money'),
        ],
    ];
    $options = ['explorer' => (bool) $explorer, 'groupBy' => $groupBy, 'tones' => $tones];

    $toolButton = 'grid size-8 place-items-center rounded-lg text-secondary-dark transition-colors hover:bg-board-head disabled:opacity-35 dark:text-ink dark:hover:bg-white/10';
    $segment = 'inline-flex items-center rounded-lg border border-secondary-soft bg-white p-0.5 dark:border-white/15 dark:bg-transparent';
    $segmentButton = 'rounded-md px-2.5 py-1 text-xs font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary';
    $segmentOn = 'bg-secondary-dark text-white shadow-sm dark:bg-ink dark:text-surface-0';
    $segmentOff = 'text-secondary-medium hover:text-secondary-dark dark:text-ink-muted dark:hover:text-ink';
    $chip = 'inline-flex cursor-pointer items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary';
    $chipOn = 'border-secondary-dark bg-secondary-dark text-white dark:border-ink dark:bg-ink dark:text-surface-0';
    $chipOff = 'border-secondary-soft text-secondary-medium hover:border-secondary-light hover:text-secondary-dark dark:border-white/15 dark:text-ink-muted dark:hover:text-ink';
    $control = 'rounded-lg border border-secondary-soft bg-white px-2.5 py-1.5 text-xs text-secondary-dark dark:border-field-line dark:bg-surface-2 dark:text-ink';
    $menu = 'absolute end-0 top-full z-40 mt-1.5 rounded-xl border border-secondary-soft bg-white p-1.5 shadow-[0_8px_24px_rgba(16,24,40,0.12),0_2px_6px_rgba(16,24,40,0.06)] dark:border-white/15 dark:bg-surface-3 dark:shadow-raised';
    $menuItem = 'flex w-full items-center gap-2 rounded-lg px-2.5 py-1.5 text-start text-xs text-secondary-dark hover:bg-board-head dark:text-ink dark:hover:bg-white/10';
    $menuHeading = 'px-2.5 pt-2 pb-1 type-label text-secondary-light dark:text-ink-muted';
    $inspectorLabel = 'type-label text-secondary-light dark:text-ink-muted';
@endphp

<div
    {{ $attributes->class('gantt flex flex-col') }}
    x-data="ganttChart(@js($payload), @js($scope), @js((bool) $editable), @js($labels), @js($options))"
    x-on:resize.window.debounce.150ms="measure()"
    x-on:click.window="linkMenu = null"
    x-on:keydown="onRootKeydown($event)"
    x-on:keydown.window="onWindowKeydown($event)"
    data-gantt
>
    @if ($explorer)
        {{-- Signal line: the whole plan in one reading, each figure a filter --}}
        <div class="gantt-no-print flex flex-wrap items-center gap-x-5 gap-y-3 border-b border-board-line px-4 py-3" data-gantt-signals>
            <button
                type="button"
                x-on:click="zoomToFit()"
                class="group/span flex min-w-0 items-center gap-3.5 rounded-lg text-start focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary"
                title="{{ __('gantt.fit_whole_plan') }}"
            >
                <span class="flex min-w-0 flex-col gap-0.5">
                    <span class="flex items-center gap-1.5 text-sm font-bold tabular-nums text-secondary-dark dark:text-ink">
                        <span x-text="formatMonth(signals.start)"></span>
                        <flux:icon.arrow-long-right variant="micro" class="size-3.5 text-secondary-light rtl:rotate-180" />
                        <span x-text="formatMonth(signals.end)"></span>
                    </span>
                    <span class="type-meta text-secondary-medium dark:text-ink-muted">
                        <span class="font-semibold tabular-nums" x-bind:class="signals.behind ? 'text-status-warn' : 'text-secondary-dark dark:text-ink'" x-text="signals.progress + '%'"></span> {{ __('gantt.signals.complete') }}
                        <span aria-hidden="true">·</span>
                        <span class="tabular-nums" x-text="signals.elapsed + '%'"></span> {{ __('gantt.signals.time_elapsed') }}
                        <template x-if="signals.behind">
                            <span class="font-semibold text-status-warn"><span aria-hidden="true">·</span> {{ __('gantt.signals.behind') }}</span>
                        </template>
                    </span>
                </span>
                <span class="relative hidden h-2 w-32 shrink-0 rounded-full bg-secondary-soft/70 sm:block dark:bg-white/15" aria-hidden="true">
                    <span class="absolute inset-y-0 start-0 rounded-full" x-bind:class="signals.behind ? 'bg-status-warn' : 'bg-secondary-dark dark:bg-ink-muted'" x-bind:style="`width:${signals.progress}%`"></span>
                    <span class="absolute -top-1 h-4 w-0.5 -translate-x-1/2 rounded-full bg-status-active rtl:translate-x-1/2" x-bind:style="`inset-inline-start:${signals.elapsed}%`"></span>
                </span>
            </button>

            <span class="hidden h-8 w-px bg-board-line md:block" aria-hidden="true"></span>

            <div class="flex flex-wrap items-center gap-2" role="group" aria-label="{{ __('gantt.signals.label') }}">
                <button type="button" x-on:click="lateOnly = ! lateOnly" x-bind:aria-pressed="lateOnly" class="{{ $chip }}" x-bind:class="lateOnly ? @js($chipOn) : @js($chipOff)">
                    <span class="size-2 rounded-full bg-status-critical" aria-hidden="true"></span>
                    <span class="tabular-nums" x-bind:class="signals.late > 0 && ! lateOnly ? 'text-status-critical' : ''" x-text="signals.late"></span>
                    {{ __('gantt.signals.late') }}
                </button>
                <button type="button" x-on:click="criticalOnly = ! criticalOnly" x-bind:aria-pressed="criticalOnly" class="{{ $chip }}" x-bind:class="criticalOnly ? @js($chipOn) : @js($chipOff)">
                    <span class="size-2 rotate-45 rounded-[1px] ring-2 ring-status-critical" aria-hidden="true"></span>
                    <span class="tabular-nums" x-text="signals.critical"></span>
                    {{ __('gantt.signals.critical') }}
                </button>
                <button type="button" x-on:click="toggleDue(30)" x-bind:aria-pressed="dueWindow === 30" class="{{ $chip }}" x-bind:class="dueWindow === 30 ? @js($chipOn) : @js($chipOff)">
                    <span class="size-2 rounded-full bg-status-active" aria-hidden="true"></span>
                    <span class="tabular-nums" x-text="signals.due30"></span>
                    {{ __('gantt.signals.due_30') }}
                </button>
                <button type="button" x-on:click="toggleDue(90)" x-bind:aria-pressed="dueWindow === 90" class="{{ $chip }}" x-bind:class="dueWindow === 90 ? @js($chipOn) : @js($chipOff)">
                    <span class="size-2 rounded-full bg-status-active/40" aria-hidden="true"></span>
                    <span class="tabular-nums" x-text="signals.due90"></span>
                    {{ __('gantt.signals.due_90') }}
                </button>
                <template x-if="relations.length > 0">
                    <button type="button" x-on:click="routes = routes === 'all' ? 'selected' : 'all'" x-bind:aria-pressed="routes === 'all'" class="{{ $chip }}" x-bind:class="routes === 'all' ? 'border-funding bg-funding text-white dark:border-funding dark:bg-funding dark:text-surface-0' : @js($chipOff)">
                        <flux:icon.arrows-right-left variant="micro" class="size-3.5" x-bind:class="routes === 'all' ? '' : 'text-funding'" />
                        <span class="tabular-nums" x-text="signals.fundingLinks"></span>
                        {{ __('gantt.signals.funding_routes') }}
                    </button>
                </template>
            </div>
        </div>

        {{-- Horizon band: the whole plan in one strip, with the chart's window on it --}}
        <div class="gantt-no-print border-b border-board-line px-4 pt-2.5 pb-3" x-show="showHorizon" data-gantt-horizon>
            <div
                x-ref="horizon"
                dir="ltr"
                tabindex="0"
                role="group"
                aria-label="{{ __('gantt.horizon.label') }}"
                aria-roledescription="{{ __('gantt.horizon.role') }}"
                x-on:pointerdown="horizonDown($event)"
                x-on:keydown="horizonKeydown($event)"
                class="relative cursor-grab touch-none overflow-hidden rounded-lg bg-board-head select-none active:cursor-grabbing focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary dark:bg-black/15"
                x-bind:style="`height:${24 + horizon.lanes * (horizonLaneHeight + 3) + 14}px`"
            >
                {{-- Next 30 and 90 days --}}
                <div class="pointer-events-none absolute inset-y-0 bg-status-active/[0.07]" x-bind:style="`left:${horizonPlace(todayDay)}px;width:${90 * horizon.scale}px`" aria-hidden="true"></div>
                <div class="pointer-events-none absolute inset-y-0 bg-status-active/[0.12]" x-bind:style="`left:${horizonPlace(todayDay)}px;width:${30 * horizon.scale}px`" aria-hidden="true"></div>

                {{-- Calendar --}}
                <template x-for="cell in horizon.units" :key="cell.key">
                    <div class="pointer-events-none absolute bottom-0 h-2 border-s border-secondary-soft dark:border-white/15" x-bind:style="`left:${cell.left}px`" aria-hidden="true"></div>
                </template>
                <template x-for="year in horizon.years" :key="year.key">
                    <div class="pointer-events-none absolute inset-y-0 border-s border-secondary-soft ps-1.5 pt-1 text-[10px] font-bold tabular-nums text-secondary-medium dark:border-white/15 dark:text-ink-muted" x-bind:style="`left:${year.left}px`" x-text="year.label" aria-hidden="true"></div>
                </template>

                {{-- Records packed into lanes --}}
                <template x-for="bar in horizon.bars" :key="bar.id">
                    <button
                        type="button"
                        tabindex="-1"
                        class="absolute rounded-full transition-opacity"
                        x-bind:class="[tones.bar[bar.tone], selectedId === bar.id ? 'ring-2 ring-status-active ring-offset-1 ring-offset-board-head' : '', traced && ! traced.roles.has(bar.id) ? 'opacity-25' : '']"
                        x-bind:style="`left:${bar.left}px;width:${bar.width}px;top:${22 + bar.lane * (horizonLaneHeight + 3)}px;height:${horizonLaneHeight}px`"
                        x-bind:title="bar.name"
                        x-bind:aria-label="bar.name"
                        x-on:pointerdown.stop
                        x-on:click.stop="selectRow(bar.id)"
                    ></button>
                </template>

                {{-- Milestones, transfers and reports as ticks --}}
                <template x-for="tick in horizon.ticks" :key="'tick-' + tick.id">
                    <span class="pointer-events-none absolute bottom-1 h-1.5 w-0.5 rounded-full" x-bind:class="tones.bar[tick.tone]" x-bind:style="`left:${tick.left}px`" aria-hidden="true"></span>
                </template>

                {{-- Today --}}
                <div class="pointer-events-none absolute inset-y-0 w-0.5 bg-secondary-dark dark:bg-ink" x-bind:style="`left:${horizonPlace(todayDay)}px`" aria-hidden="true"></div>

                {{-- The chart's window --}}
                <div
                    data-horizon-window
                    class="absolute inset-y-0.5 rounded-md border-2 border-secondary-dark/70 bg-white/35 shadow-[0_1px_3px_rgba(16,24,40,0.12)] dark:border-white/80 dark:bg-white/10"
                    x-bind:style="horizonWindow"
                    aria-hidden="true"
                >
                    <span class="absolute inset-y-2 -start-[3px] w-1 rounded-full bg-secondary-dark/70 dark:bg-white/80"></span>
                    <span class="absolute inset-y-2 -end-[3px] w-1 rounded-full bg-secondary-dark/70 dark:bg-white/80"></span>
                </div>
            </div>
        </div>
    @endif

    {{-- Toolbar --}}
    <div class="gantt-toolbar flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-board-line px-4 py-2.5">
        <div class="{{ $segment }}" role="group" aria-label="{{ __('gantt.zoom') }}">
            @foreach ($zoomLevels as $level => $levelLabel)
                <button
                    type="button"
                    x-on:click="setZoom('{{ $level }}')"
                    x-bind:aria-pressed="zoom === '{{ $level }}'"
                    x-bind:class="zoom === '{{ $level }}' ? @js($segmentOn) : @js($segmentOff)"
                    class="{{ $segmentButton }}"
                >{{ $levelLabel }}</button>
            @endforeach
        </div>

        <div class="flex items-center gap-1">
            <button type="button" x-on:click="goToToday()" class="inline-flex h-8 items-center gap-1.5 rounded-lg px-2.5 text-xs font-semibold text-secondary-dark hover:bg-board-head dark:text-ink dark:hover:bg-white/10">
                <span class="size-2 rounded-full bg-secondary-dark ring-2 ring-secondary-dark/20 dark:bg-ink dark:ring-white/25" aria-hidden="true"></span>{{ __('ui.today') }}
            </button>
            <button type="button" x-on:click="zoomToFit()" class="inline-flex h-8 items-center gap-1.5 rounded-lg px-2.5 text-xs font-semibold text-secondary-dark hover:bg-board-head dark:text-ink dark:hover:bg-white/10">
                <flux:icon.arrows-pointing-in variant="micro" class="size-3.5" />{{ __('gantt.fit') }}
            </button>
        </div>

        <span class="h-5 w-px bg-board-line" aria-hidden="true"></span>

        <div class="flex items-center gap-2">
            <span class="text-xs font-semibold text-secondary-medium dark:text-ink-muted">{{ __('gantt.outline') }}</span>
            <div class="{{ $segment }}" role="group" aria-label="{{ __('gantt.outline') }}">
                <template x-for="level in Math.min(3, maxDepth)" :key="'outline-' + level">
                    <button
                        type="button"
                        x-on:click="outline(level)"
                        x-bind:aria-pressed="outlineLevel === level"
                        x-bind:class="outlineLevel === level ? @js($segmentOn) : @js($segmentOff)"
                        class="{{ $segmentButton }} min-w-7 tabular-nums"
                        x-bind:title="@js(__('gantt.outline_level')) + ' ' + level"
                        x-text="level"
                    ></button>
                </template>
                <button type="button" x-on:click="outline(0)" x-bind:aria-pressed="outlineLevel === 0" x-bind:class="outlineLevel === 0 ? @js($segmentOn) : @js($segmentOff)" class="{{ $segmentButton }}">{{ __('gantt.outline_all') }}</button>
            </div>
        </div>

        @if (! $explorer)
            <span class="h-5 w-px bg-board-line" aria-hidden="true"></span>
            <div class="flex items-center gap-2">
                <button type="button" x-on:click="lateOnly = ! lateOnly" class="{{ $chip }}" x-bind:class="lateOnly ? @js($chipOn) : @js($chipOff)" x-bind:aria-pressed="lateOnly">
                    <span class="size-2 rounded-full bg-status-critical" aria-hidden="true"></span>{{ __('gantt.late_only') }}
                </button>
                <button type="button" x-on:click="criticalOnly = ! criticalOnly" class="{{ $chip }}" x-bind:class="criticalOnly ? @js($chipOn) : @js($chipOff)" x-bind:aria-pressed="criticalOnly">
                    <span class="size-2 rotate-45 rounded-[1px] ring-2 ring-status-critical" aria-hidden="true"></span>{{ __('gantt.critical_only') }}
                </button>
            </div>
        @endif

        @if ($groupOptions !== [])
            <span class="h-5 w-px bg-board-line" aria-hidden="true"></span>
            <div class="flex items-center gap-2">
                <span class="text-xs font-semibold text-secondary-medium dark:text-ink-muted">{{ __('gantt.group_by') }}</span>
                <div class="{{ $segment }}" role="group" aria-label="{{ __('gantt.group_by') }}">
                    @foreach ($groupOptions as $grouping => $groupingLabel)
                        <button
                            type="button"
                            x-on:click="setGroup(@js($grouping))"
                            x-bind:aria-pressed="groupBy === @js($grouping)"
                            x-bind:class="groupBy === @js($grouping) ? @js($segmentOn) : @js($segmentOff)"
                            class="{{ $segmentButton }}"
                        >{{ $groupingLabel }}</button>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($editable)
            <div class="flex items-center gap-0.5" role="group" aria-label="{{ __('gantt.edit_tools') }}">
                <button type="button" x-on:click="undo()" x-bind:disabled="busy || undoStack.length === 0" class="{{ $toolButton }}" title="{{ __('gantt.undo') }} (Ctrl+Z)" aria-label="{{ __('gantt.undo') }}">
                    <flux:icon.arrow-uturn-left variant="micro" />
                </button>
                <button type="button" x-on:click="redo()" x-bind:disabled="busy || redoStack.length === 0" class="{{ $toolButton }}" title="{{ __('gantt.redo') }} (Ctrl+Y)" aria-label="{{ __('gantt.redo') }}">
                    <flux:icon.arrow-uturn-right variant="micro" />
                </button>
                <span class="mx-1 h-4 w-px bg-board-line" aria-hidden="true"></span>
                <button type="button" x-on:click="outdent()" x-bind:disabled="busy || !selectedId" class="{{ $toolButton }}" title="{{ __('gantt.outdent') }} (Shift+Tab)" aria-label="{{ __('gantt.outdent') }}">
                    <flux:icon.arrow-left variant="micro" />
                </button>
                <button type="button" x-on:click="indent()" x-bind:disabled="busy || !selectedId" class="{{ $toolButton }}" title="{{ __('gantt.indent') }} (Tab)" aria-label="{{ __('gantt.indent') }}">
                    <flux:icon.arrow-right variant="micro" />
                </button>
                <span x-show="busy" x-cloak class="ms-1.5 inline-flex items-center gap-1.5 text-xs text-secondary-medium dark:text-ink-muted">
                    <span class="size-1.5 animate-pulse rounded-full bg-primary dark:bg-ink"></span>{{ __('gantt.saving') }}
                </span>
            </div>
        @endif

        <div class="ms-auto flex w-full items-center gap-1.5 sm:w-auto">
            <label class="relative flex min-w-0 flex-1 items-center sm:flex-none">
                <span class="sr-only">{{ __('gantt.search') }}</span>
                <flux:icon.magnifying-glass variant="micro" class="pointer-events-none absolute start-2.5 size-3.5 text-secondary-light" />
                <input
                    type="search"
                    x-ref="search"
                    x-model.debounce.150ms="search"
                    x-on:keydown.escape="search = ''; $el.blur()"
                    placeholder="{{ __('gantt.search') }}"
                    class="h-8 w-full ps-8 pe-7 sm:w-48 {{ $control }} placeholder:text-secondary-light focus:border-secondary-light focus:outline-none"
                >
                <kbd class="pointer-events-none absolute end-2 rounded border border-secondary-soft px-1 font-sans text-[10px] font-semibold text-secondary-light dark:border-white/15" x-show="search === ''" aria-hidden="true">/</kbd>
            </label>

            {{-- Display settings --}}
            <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape.stop="open = false">
                <button type="button" x-on:click="open = ! open" class="{{ $toolButton }}" x-bind:aria-expanded="open" aria-label="{{ __('gantt.display') }}" title="{{ __('gantt.display') }}">
                    <flux:icon.adjustments-horizontal variant="micro" />
                </button>
                <div x-show="open" x-cloak x-transition.origin.top.right class="{{ $menu }} w-72" role="dialog" aria-label="{{ __('gantt.display') }}">
                    <p class="{{ $menuHeading }}">{{ __('gantt.display_show') }}</p>
                    @foreach (array_filter([
                        'showLinks' => __('gantt.links'),
                        'showCritical' => __('gantt.critical_path'),
                        'trace' => $explorer ? __('gantt.route_tracing') : null,
                        'showHorizon' => $explorer ? __('gantt.horizon.toggle') : null,
                    ]) as $setting => $settingLabel)
                        <button type="button" x-on:click="{{ $setting }} = ! {{ $setting }}" class="{{ $menuItem }} justify-between" role="switch" x-bind:aria-checked="{{ $setting }}">
                            {{ $settingLabel }}
                            <span class="relative h-4 w-7 rounded-full transition-colors" x-bind:class="{{ $setting }} ? 'bg-secondary-dark dark:bg-ink' : 'bg-secondary-soft dark:bg-white/20'" aria-hidden="true">
                                <span class="absolute top-0.5 size-3 rounded-full bg-white shadow-sm transition-[inset-inline-start] dark:bg-surface-1" x-bind:class="{{ $setting }} ? 'start-3.5' : 'start-0.5'"></span>
                            </span>
                        </button>
                    @endforeach

                    <p class="{{ $menuHeading }}">{{ __('gantt.bar_labels') }}</p>
                    <div class="px-2.5 pb-1">
                        <div class="{{ $segment }} w-full">
                            @foreach (['name' => __('gantt.bar_label_options.name'), 'owner' => __('gantt.bar_label_options.owner'), 'none' => __('gantt.bar_label_options.none')] as $option => $optionLabel)
                                <button type="button" x-on:click="barLabels = '{{ $option }}'" x-bind:aria-pressed="barLabels === '{{ $option }}'" x-bind:class="barLabels === '{{ $option }}' ? @js($segmentOn) : @js($segmentOff)" class="{{ $segmentButton }} flex-1">{{ $optionLabel }}</button>
                            @endforeach
                        </div>
                    </div>

                    <p class="{{ $menuHeading }}">{{ __('gantt.row_density') }}</p>
                    <div class="px-2.5 pb-1">
                        <div class="{{ $segment }} w-full">
                            @foreach (['comfortable' => __('gantt.density.comfortable'), 'compact' => __('gantt.density.compact')] as $option => $optionLabel)
                                <button type="button" x-on:click="density = '{{ $option }}'" x-bind:aria-pressed="density === '{{ $option }}'" x-bind:class="density === '{{ $option }}' ? @js($segmentOn) : @js($segmentOff)" class="{{ $segmentButton }} flex-1">{{ $optionLabel }}</button>
                            @endforeach
                        </div>
                    </div>

                    <p class="{{ $menuHeading }}">{{ __('gantt.columns.heading') }}</p>
                    <div class="grid grid-cols-2 gap-0.5 pb-1">
                        @foreach ($columns as $column => $columnLabel)
                            <label class="{{ $menuItem }} cursor-pointer">
                                <input type="checkbox" class="size-3.5 rounded accent-secondary-dark dark:accent-ink" x-bind:checked="columns.{{ $column }}" x-on:change="toggleColumn('{{ $column }}')">
                                <span class="truncate">{{ $columnLabel }}</span>
                            </label>
                        @endforeach
                    </div>

                    @unless ($editable)
                        <p class="{{ $menuHeading }}">{{ __('gantt.weekend') }}</p>
                        <div class="px-2.5 pb-1.5">
                            <select x-bind:value="weekendPreset" x-on:change="changeWeekend($event.target.value)" class="w-full {{ $control }}" aria-label="{{ __('gantt.weekend') }}">
                                @foreach ($weekendPresets as $preset => $presetLabel)
                                    <option value="{{ $preset }}">{{ $presetLabel }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endunless
                </div>
            </div>

            {{-- Export --}}
            <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape.stop="open = false">
                <button type="button" x-on:click="open = ! open" class="inline-flex h-8 items-center gap-1.5 {{ $control }} font-semibold hover:border-secondary-light" x-bind:aria-expanded="open">
                    <flux:icon.arrow-down-tray variant="micro" class="size-3.5" />{{ __('gantt.export') }}
                </button>
                <div x-show="open" x-cloak x-transition.origin.top.right class="{{ $menu }} w-48">
                    <button type="button" x-on:click="open = false; exportCsv()" class="{{ $menuItem }}">{{ __('gantt.export_csv') }}</button>
                    <button type="button" x-on:click="open = false; printChart()" class="{{ $menuItem }}">{{ __('gantt.export_pdf') }}</button>
                </div>
            </div>

            {{-- Keyboard shortcuts --}}
            <div class="relative" x-on:click.outside="shortcutsOpen = false">
                <button type="button" x-on:click="shortcutsOpen = ! shortcutsOpen" class="{{ $toolButton }}" x-bind:aria-expanded="shortcutsOpen" aria-label="{{ __('gantt.shortcuts.heading') }}" title="{{ __('gantt.shortcuts.heading') }} (?)">
                    <flux:icon.question-mark-circle variant="micro" />
                </button>
                <div x-show="shortcutsOpen" x-cloak x-transition.origin.top.right class="{{ $menu }} w-72" role="dialog" aria-label="{{ __('gantt.shortcuts.heading') }}">
                    <p class="{{ $menuHeading }}">{{ __('gantt.shortcuts.heading') }}</p>
                    <dl class="grid grid-cols-[auto_1fr] items-center gap-x-3 gap-y-1.5 px-2.5 pb-2 text-xs">
                        @foreach ($shortcuts as $keys => $shortcutLabel)
                            <dt><kbd class="inline-block min-w-6 rounded-md border border-secondary-soft bg-board-head px-1.5 py-0.5 text-center font-sans text-[11px] font-semibold text-secondary-dark dark:border-white/15 dark:bg-white/10 dark:text-ink">{{ $keys }}</kbd></dt>
                            <dd class="text-secondary-medium dark:text-ink-muted">{{ $shortcutLabel }}</dd>
                        @endforeach
                    </dl>
                    <p class="border-t border-board-line px-2.5 pt-2 pb-1 text-[11px] text-secondary-medium dark:text-ink-muted">{{ $editable ? __('gantt.hint') : __('gantt.hint_readonly') }}</p>
                </div>
            </div>
        </div>
    </div>

    @if ($editable)
        {{-- Schedule settings the project keeps: working week and baselines --}}
        <div class="gantt-toolbar flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-board-line px-4 py-2 text-xs font-semibold text-secondary-medium dark:text-ink-muted">
            <label class="inline-flex items-center gap-2">
                {{ __('gantt.weekend') }}
                <select x-bind:value="weekendPreset" x-on:change="changeWeekend($event.target.value)" x-bind:disabled="busy" class="{{ $control }}">
                    @foreach ($weekendPresets as $preset => $presetLabel)
                        <option value="{{ $preset }}">{{ $presetLabel }}</option>
                    @endforeach
                </select>
            </label>

            <span class="mx-1 h-4 w-px bg-board-line" aria-hidden="true"></span>

            <div class="flex flex-wrap items-center gap-2">
                <span>{{ __('gantt.baseline') }}</span>
                <select x-on:change="showBaseline($event.target.value)" x-bind:disabled="busy" class="{{ $control }} max-w-48" aria-label="{{ __('gantt.baseline') }}">
                    <option value="" x-bind:selected="baselineId === null">{{ __('gantt.no_baseline') }}</option>
                    <template x-for="saved in baselines" :key="saved.id">
                        <option x-bind:value="saved.id" x-bind:selected="saved.id === baselineId" x-text="saved.name"></option>
                    </template>
                </select>
                <button type="button" x-show="baselineId !== null" x-on:click="deleteBaseline()" x-bind:disabled="busy" class="{{ $toolButton }}" aria-label="{{ __('gantt.delete_baseline') }}" title="{{ __('gantt.delete_baseline') }}">
                    <flux:icon.trash variant="micro" class="size-3.5" />
                </button>
                <input type="text" x-model="baselineName" maxlength="255" placeholder="{{ __('gantt.baseline_name') }}" aria-label="{{ __('gantt.baseline_name') }}" class="w-40 {{ $control }} placeholder:text-secondary-light" x-on:keydown.enter.prevent="saveBaseline()">
                <button type="button" x-on:click="saveBaseline()" x-bind:disabled="busy" class="rounded-lg bg-secondary-dark px-3 py-1.5 text-xs font-semibold text-white hover:bg-primary dark:hover:bg-ink/85 disabled:opacity-40 dark:bg-ink dark:text-surface-0">{{ __('gantt.save_baseline') }}</button>
            </div>
        </div>
    @endif

    {{-- Split grid + timeline (one scroller so both halves share vertical scroll), with the inspector at the end edge --}}
    <div class="gantt-split relative flex {{ $height }}">
        <div
            x-ref="scroller"
            dir="ltr"
            tabindex="0"
            role="treegrid"
            aria-label="{{ __('gantt.chart') }}"
            x-on:scroll="onScroll($event)"
            x-on:wheel="onWheel($event)"
            x-on:keydown="onKeydown($event)"
            class="gantt-scroller relative h-full min-w-0 flex-1 overflow-auto overscroll-contain focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary/40"
        >
            <div class="relative" x-bind:style="`width:${gridWidth + chartWidth}px`">
                {{-- Header --}}
                <div class="gantt-head sticky top-0 z-30 flex h-14 border-b border-board-line">
                    <div class="gantt-cell sticky left-0 z-10 flex shrink-0 items-end border-e border-board-line pb-2 type-label text-secondary-medium dark:text-ink-muted" x-bind:style="`width:${gridWidth}px`">
                        <div class="grid w-full items-center gap-2 overflow-hidden px-3" x-bind:style="gridTemplate">
                            <span x-show="showWbs">{{ __('gantt.wbs') }}</span>
                            <span>{{ __('gantt.task_name') }}</span>
                            <span x-show="shown.status" class="truncate">{{ __('gantt.columns.status') }}</span>
                            <span x-show="shown.owner" class="truncate">{{ __('gantt.columns.owner') }}</span>
                            <span x-show="shown.start" class="text-end">{{ __('gantt.start') }}</span>
                            <span x-show="shown.finish" class="text-end">{{ __('gantt.finish') }}</span>
                            <span x-show="shown.duration" class="text-end" title="{{ __('gantt.working_days') }}">{{ __('gantt.duration_short') }}</span>
                            <span x-show="shown.progress" class="text-end">%</span>
                            <span x-show="shown.predecessors" class="truncate" title="{{ __('gantt.predecessors') }}">{{ __('gantt.predecessors_short') }}</span>
                            <span x-show="shown.float" class="text-end" title="{{ __('gantt.total_float') }}">{{ __('gantt.float_short') }}</span>
                            <span class="text-end" x-show="hasBaseline" title="{{ __('gantt.variance') }}">{{ __('gantt.variance_short') }}</span>
                        </div>
                        <span
                            class="absolute inset-y-0 -right-1 w-2 cursor-col-resize hover:bg-secondary-soft/60"
                            x-on:pointerdown.prevent="startResize($event)"
                            role="separator"
                            aria-orientation="vertical"
                            aria-label="{{ __('gantt.resize_grid') }}"
                        ></span>
                    </div>
                    <div class="relative shrink-0" x-bind:style="`width:${chartWidth}px`">
                        <template x-for="cell in topTier" :key="cell.key">
                            <div class="absolute top-0 h-7 border-s border-board-line" x-bind:style="`left:${x(cell.start)}px;width:${cell.days * ppd}px`">
                                {{-- The label stays readable while its month or year scrolls past. --}}
                                <span class="sticky inline-block truncate px-2 pt-1.5 text-xs font-bold text-secondary-dark dark:text-ink" x-bind:style="`left:${gridWidth}px;max-width:${cell.days * ppd}px`" x-text="cell.label"></span>
                            </div>
                        </template>
                        <template x-for="cell in bottomTier" :key="cell.key">
                            <div
                                class="absolute bottom-0 h-7 truncate border-s border-t border-board-line pt-1.5 text-center text-[11px] font-medium tabular-nums text-secondary-medium dark:text-ink-muted"
                                x-bind:class="zoom === 'year' ? 'top-0 h-14 pt-5' : ''"
                                x-bind:style="`left:${x(cell.start)}px;width:${cell.days * ppd}px`"
                                x-text="cell.days * ppd >= 18 ? cell.label : ''"
                            ></div>
                        </template>
                        <div class="absolute bottom-1 z-10 -translate-x-1/2 rounded-full bg-secondary-dark px-2 py-px text-[10px] font-bold text-white shadow-sm dark:bg-ink dark:text-surface-0" x-bind:style="`left:${x(todayDay) + ppd / 2}px`">{{ __('ui.today') }}</div>
                    </div>
                </div>

                {{-- Body --}}
                <div class="relative" x-bind:style="`height:${Math.max(bodyHeight, 1)}px`">
                    {{-- Background: weekends, unit lines, the due window, today --}}
                    <div class="pointer-events-none absolute inset-y-0" x-bind:style="`left:${gridWidth}px;width:${chartWidth}px`" aria-hidden="true">
                        <template x-for="day in weekendStripes" :key="day">
                            <div class="absolute inset-y-0 bg-secondary-soft/25 dark:bg-black/15" x-bind:style="`left:${x(day)}px;width:${ppd}px`"></div>
                        </template>
                        <template x-for="cell in bottomTier" :key="'line-' + cell.key">
                            <div class="absolute inset-y-0 border-s border-board-line/50" x-bind:style="`left:${x(cell.start)}px`"></div>
                        </template>
                        <div x-show="dueWindow > 0" class="absolute inset-y-0 bg-status-active/[0.07]" x-bind:style="`left:${x(todayDay)}px;width:${(dueWindow + 1) * ppd}px`"></div>
                        <div class="absolute inset-y-0 w-0.5 bg-secondary-dark/70 dark:bg-white/70" x-bind:style="`left:${x(todayDay) + ppd / 2}px`"></div>
                    </div>

                    <template x-for="item in windowed" :key="item.row.id">
                        <div
                            class="absolute inset-x-0 flex"
                            role="row"
                            x-bind:data-row-id="item.row.id"
                            x-bind:data-group="item.row.kind === 'group'"
                            x-bind:data-dim="isDimmed(item.row)"
                            x-bind:aria-level="item.row.depth + 1"
                            x-bind:aria-expanded="item.row.hasChildren ? String(!collapsed[item.row.id]) : null"
                            x-bind:aria-selected="selectedId === item.row.id"
                            x-bind:style="`top:${item.index * rowHeight}px;height:${rowHeight}px`"
                            x-on:click="onRowClick(item.row)"
                        >
                            {{-- Grid cells --}}
                            <div
                                class="gantt-cell sticky left-0 z-20 grid shrink-0 items-center gap-2 overflow-clip border-e border-b border-board-line px-3 text-xs"
                                x-bind:style="`width:${gridWidth}px;${gridTemplate}`"
                            >
                                <span x-show="showWbs" class="flex items-center gap-1 tabular-nums text-secondary-medium dark:text-ink-muted">
                                    <span x-show="isCriticalShown(item.row)" class="size-1.5 shrink-0 rounded-full bg-status-critical" aria-hidden="true"></span>
                                    <span x-text="item.row.wbs"></span>
                                </span>
                                <span class="flex min-w-0 items-center gap-1.5" x-bind:style="`padding-left:${item.row.depth * indent}px`">
                                    <button
                                        type="button"
                                        class="grid size-4 shrink-0 place-items-center rounded text-secondary-medium hover:bg-secondary-soft/60 hover:text-secondary-dark dark:text-ink-muted dark:hover:bg-white/10 dark:hover:text-ink"
                                        x-bind:class="item.row.hasChildren ? '' : 'invisible'"
                                        x-on:click.stop="toggle(item.row)"
                                        x-bind:aria-label="collapsed[item.row.id] ? @js(__('gantt.expand')) : @js(__('gantt.collapse'))"
                                    >
                                        <svg viewBox="0 0 12 12" class="size-3 transition-transform duration-150" x-bind:class="collapsed[item.row.id] ? '' : 'rotate-90'" aria-hidden="true"><path d="M4 2.5 7.5 6 4 9.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    </button>
                                    @foreach ($icons as $iconKey => [$iconName, $iconTint])
                                        <flux:icon :name="$iconName" variant="micro" class="size-3.5 shrink-0 {{ $iconTint }}" x-show="iconKey(item.row) === '{{ $iconKey }}'" />
                                    @endforeach
                                    <template x-if="isEditing(item.row, 'name')">
                                        <input type="text" data-gantt-editor dir="auto" maxlength="255" x-model="editing.value" x-on:keydown="onEditorKeydown($event)" x-on:blur="commitEdit()" x-on:click.stop class="gantt-editor min-w-0 flex-1">
                                    </template>
                                    <span
                                        x-show="!isEditing(item.row, 'name')"
                                        dir="auto"
                                        class="truncate"
                                        x-bind:class="item.row.kind === 'group' ? 'text-[11px] font-bold tracking-wide text-secondary-dark uppercase dark:text-ink' : (item.row.summary ? 'font-semibold text-secondary-dark dark:text-ink' : 'text-secondary-dark dark:text-ink-muted')"
                                        x-text="item.row.name"
                                        x-bind:title="item.row.name"
                                        x-on:dblclick.stop="canEditCell(item.row, 'name') ? startEdit(item.row, 'name') : open(item.row)"
                                    ></span>
                                    @if ($explorer)
                                        <span
                                            x-show="routeTag(item.row) !== ''"
                                            class="shrink-0 rounded-full px-1.5 py-px text-[10px] leading-4 font-bold"
                                            x-bind:class="roleOf(item.row) === 'money' ? 'bg-funding-soft text-funding' : 'bg-status-active-soft text-status-active'"
                                            x-text="routeTag(item.row)"
                                        ></span>
                                    @endif
                                    <button
                                        type="button"
                                        x-show="item.row.url && !isEditing(item.row, 'name')"
                                        x-on:click.stop="open(item.row)"
                                        class="gantt-no-print gantt-open ms-auto size-5 shrink-0 place-items-center rounded text-secondary-medium hover:text-primary dark:hover:text-brand-text dark:text-ink-muted"
                                        aria-label="{{ __('gantt.open') }}"
                                    ><flux:icon.eye variant="micro" class="size-3.5" /></button>
                                </span>
                                <span x-show="shown.status" class="min-w-0">
                                    <span
                                        x-show="item.row.statusLabel"
                                        class="inline-flex max-w-full truncate rounded-full px-2 py-0.5 text-[10.5px] leading-4 font-semibold ring-1 ring-inset"
                                        x-bind:class="tones.pill[item.row.tone]"
                                        x-text="item.row.statusLabel"
                                        x-bind:title="item.row.statusLabel"
                                    ></span>
                                </span>
                                <span x-show="shown.owner" class="flex min-w-0 items-center gap-1.5">
                                    <template x-if="item.row.owner && item.row.kind !== 'group'">
                                        <span class="flex min-w-0 items-center gap-1.5" x-bind:title="item.row.owner">
                                            <span class="grid size-5 shrink-0 place-items-center rounded-full bg-secondary-soft text-[9px] font-bold text-secondary-dark dark:bg-white/15 dark:text-ink" x-text="initials(item.row.owner)" aria-hidden="true"></span>
                                            <span class="truncate text-secondary-dark dark:text-ink-muted" dir="auto" x-text="item.row.owner"></span>
                                        </span>
                                    </template>
                                </span>
                                @foreach (['start' => 'startDay', 'end' => 'endDay'] as $field => $dayKey)
                                    <span x-show="shown.{{ $field === 'start' ? 'start' : 'finish' }}" class="relative min-w-0 text-end tabular-nums text-secondary-medium dark:text-ink-muted" x-bind:class="canEditCell(item.row, '{{ $field }}') ? 'cursor-text hover:text-secondary-dark dark:hover:text-ink' : ''" x-on:dblclick.stop="startEdit(item.row, '{{ $field }}')">
                                        <template x-if="isEditing(item.row, '{{ $field }}')">
                                            <input type="date" data-gantt-editor x-model="editing.value" x-on:keydown="onEditorKeydown($event)" x-on:blur="commitEdit()" x-on:click.stop class="gantt-editor absolute end-0 top-1/2 z-30 w-32 -translate-y-1/2 shadow-md">
                                        </template>
                                        <span x-show="!isEditing(item.row, '{{ $field }}')" x-text="formatDay(item.row.{{ $dayKey }})"></span>
                                    </span>
                                @endforeach
                                <span x-show="shown.duration" class="text-end tabular-nums text-secondary-medium dark:text-ink-muted" x-text="item.row.kind === 'group' ? '' : duration(item.row)"></span>
                                <span x-show="shown.progress" class="relative flex min-w-0 items-center justify-end gap-1.5 tabular-nums font-semibold" x-bind:class="(item.row.progress >= 100 ? 'text-status-good' : 'text-secondary-dark dark:text-ink') + (canEditCell(item.row, 'progress') ? ' cursor-text' : '')" x-on:dblclick.stop="startEdit(item.row, 'progress')">
                                    <template x-if="isEditing(item.row, 'progress')">
                                        <input type="number" min="0" max="100" step="5" data-gantt-editor x-model="editing.value" x-on:keydown="onEditorKeydown($event)" x-on:blur="commitEdit()" x-on:click.stop class="gantt-editor absolute end-0 top-1/2 z-30 w-16 -translate-y-1/2 text-end shadow-md">
                                    </template>
                                    <template x-if="!isEditing(item.row, 'progress') && item.row.kind !== 'group'">
                                        <span class="h-1 w-6 shrink-0 overflow-hidden rounded-full bg-secondary-soft/80 dark:bg-white/15" aria-hidden="true">
                                            <span class="block h-full rounded-full" x-bind:class="item.row.progress >= 100 ? 'bg-status-good' : 'bg-secondary-dark dark:bg-ink-muted'" x-bind:style="`width:${item.row.progress}%`"></span>
                                        </span>
                                    </template>
                                    <span x-show="!isEditing(item.row, 'progress')" x-text="item.row.kind === 'group' ? '' : item.row.progress"></span>
                                </span>
                                <span x-show="shown.predecessors" class="truncate tabular-nums text-secondary-medium dark:text-ink-muted" x-text="item.row.predecessors ?? ''" x-bind:title="item.row.predecessors ?? ''"></span>
                                <span x-show="shown.float" class="text-end tabular-nums" x-bind:class="item.row.float !== null && item.row.float <= 0 ? 'font-semibold text-status-critical' : 'text-secondary-medium dark:text-ink-muted'" x-text="item.row.float === null || item.row.float === undefined ? '' : item.row.float + 'd'"></span>
                                <span
                                    x-show="hasBaseline"
                                    class="text-end tabular-nums font-semibold"
                                    x-bind:class="variance(item.row) > 0 ? 'text-status-critical' : (variance(item.row) < 0 ? 'text-status-good' : 'text-secondary-medium dark:text-ink-muted')"
                                    x-text="variance(item.row) === null ? '' : (variance(item.row) > 0 ? '+' : '') + variance(item.row)"
                                ></span>
                            </div>

                            {{-- Timeline lane --}}
                            <div class="gantt-lane relative shrink-0 border-b border-board-line/50" x-bind:style="`width:${chartWidth}px`" x-on:dblclick="open(item.row)">
                                {{-- A group's whole span, as a quiet band --}}
                                <template x-if="item.row.kind === 'group' && item.row.startDay !== null">
                                    <div class="absolute inset-y-2 rounded-md bg-secondary-soft/40 dark:bg-white/[0.07]" x-bind:style="groupStyle(item.row)" aria-hidden="true"></div>
                                </template>

                                {{-- Baseline (what was agreed), under the planned bar --}}
                                <div class="gantt-baseline absolute h-1.5 rounded-sm" x-bind:style="baselineStyle(item.row) + `;top:calc(50% + ${barHeight / 2 + 2}px)`" aria-hidden="true"></div>

                                {{-- Slip: late work runs on to today --}}
                                <div class="gantt-slip absolute top-1/2 -translate-y-1/2 rounded-e-[4px]" x-bind:style="slipStyle(item.row) + `;height:${Math.max(6, barHeight - 6)}px`" aria-hidden="true"></div>

                                <template x-if="item.row.diamond && item.row.startDay !== null">
                                    <div
                                        class="absolute top-1/2 -translate-x-1/2 -translate-y-1/2 rotate-45 rounded-[2px]"
                                        x-bind:class="[tones.bar[item.row.tone], diamondRing(item.row), density === 'compact' ? 'size-2.5' : 'size-3', settled ? '' : 'gantt-pop']"
                                        x-bind:style="diamondStyle(item.row) + `;--gantt-delay:${Math.min(item.index, 30) * 16}ms`"
                                        x-bind:data-draggable="canDrag(item.row)"
                                        x-on:pointerdown="beginDrag($event, item.row, 'move')"
                                        x-on:mouseenter="showTip(item.row, $event)"
                                        x-on:mousemove="showTip(item.row, $event)"
                                        x-on:mouseleave="hideTip()"
                                    ></div>
                                </template>

                                <template x-if="!item.row.diamond && item.row.summary && item.row.kind !== 'group' && item.row.startDay !== null">
                                    <div
                                        class="absolute top-1/2 h-[7px] -translate-y-1/2"
                                        x-bind:class="settled ? '' : 'gantt-grow'"
                                        x-bind:style="barStyle(item.row) + `;--gantt-delay:${Math.min(item.index, 30) * 16}ms`"
                                        x-on:mouseenter="showTip(item.row, $event)"
                                        x-on:mousemove="showTip(item.row, $event)"
                                        x-on:mouseleave="hideTip()"
                                    >
                                        <div class="absolute inset-0 rounded-[2px]" x-bind:class="[isCriticalShown(item.row) || item.row.late ? 'bg-status-critical/35' : 'bg-secondary-dark/30 dark:bg-white/30', roleOf(item.row) === 'self' ? 'ring-2 ring-status-active ring-offset-2 ring-offset-white dark:ring-offset-surface-1' : '']"></div>
                                        <div class="absolute inset-y-0 left-0 rounded-[2px]" x-bind:class="isCriticalShown(item.row) || item.row.late ? 'bg-status-critical' : 'bg-secondary-dark dark:bg-ink-muted'" x-bind:style="`width:${item.row.progress}%`"></div>
                                        <span class="absolute -bottom-1.5 left-0 border-x-4 border-t-[6px] border-x-transparent" x-bind:class="item.row.late ? 'border-t-status-critical' : 'border-t-secondary-dark dark:border-t-ink-muted'" aria-hidden="true"></span>
                                        <span class="absolute right-0 -bottom-1.5 border-x-4 border-t-[6px] border-x-transparent" x-bind:class="item.row.late ? 'border-t-status-critical' : 'border-t-secondary-dark dark:border-t-ink-muted'" aria-hidden="true"></span>
                                    </div>
                                </template>

                                <template x-if="!item.row.diamond && !item.row.summary && item.row.startDay !== null">
                                    <div
                                        data-bar
                                        class="group/bar absolute inset-y-0"
                                        x-bind:data-draggable="canDrag(item.row)"
                                        x-bind:style="barStyle(item.row)"
                                        x-on:pointerdown="beginDrag($event, item.row, 'move')"
                                        x-on:mouseenter="showTip(item.row, $event)"
                                        x-on:mousemove="showTip(item.row, $event)"
                                        x-on:mouseleave="hideTip()"
                                    >
                                        <template x-if="canDrag(item.row)">
                                            <div class="gantt-no-print">
                                                <span class="absolute top-1/2 -left-1 z-10 h-5 w-2 -translate-y-1/2 cursor-ew-resize rounded-sm bg-secondary-dark opacity-0 group-hover/bar:opacity-70 dark:bg-ink" x-on:pointerdown="beginDrag($event, item.row, 'start')" aria-hidden="true"></span>
                                                <span class="absolute top-1/2 -right-1 z-10 h-5 w-2 -translate-y-1/2 cursor-ew-resize rounded-sm bg-secondary-dark opacity-0 group-hover/bar:opacity-70 dark:bg-ink" x-on:pointerdown="beginDrag($event, item.row, 'end')" aria-hidden="true"></span>
                                            </div>
                                        </template>
                                        <template x-if="canDrag(item.row) && item.row.progressEditable">
                                            <span class="gantt-no-print absolute z-10 -translate-x-1/2 cursor-col-resize border-x-[5px] border-b-[6px] border-x-transparent border-b-secondary-dark opacity-0 group-hover/bar:opacity-100 dark:border-b-ink" x-bind:style="`left:${item.row.progress}%;top:calc(50% + ${barHeight / 2}px)`" x-on:pointerdown="beginDrag($event, item.row, 'progress')" aria-hidden="true"></span>
                                        </template>
                                        <div
                                            class="absolute inset-x-0 top-1/2 -translate-y-1/2 overflow-hidden rounded-[5px]"
                                            x-bind:class="[tones.track[item.row.tone], barRing(item.row), settled ? '' : 'gantt-grow']"
                                            x-bind:style="`height:${barHeight}px;--gantt-delay:${Math.min(item.index, 30) * 16}ms`"
                                        >
                                            <div class="absolute inset-y-0 left-0" x-bind:class="tones.bar[item.row.tone]" x-bind:style="`width:${item.row.progress}%`"></div>
                                        </div>
                                    </div>
                                </template>

                                {{-- Link handles: drag from a bar edge onto another bar --}}
                                <template x-if="canLink(item.row) && !item.row.summary">
                                    <div class="gantt-no-print">
                                        <span class="gantt-link-handle" x-bind:style="`left:${edgeX(item.row, 'start') - 14}px`" x-on:pointerdown="beginLink($event, item.row, 'start')" title="{{ __('gantt.drag_to_link') }}" aria-hidden="true"></span>
                                        <span class="gantt-link-handle" x-bind:style="`left:${edgeX(item.row, 'end') + 4}px`" x-on:pointerdown="beginLink($event, item.row, 'end')" title="{{ __('gantt.drag_to_link') }}" aria-hidden="true"></span>
                                    </div>
                                </template>

                                {{-- Actual (what happened) under the planned bar --}}
                                <div class="absolute bottom-[3px] h-[3px] rounded-full bg-secondary-dark/70 dark:bg-white/70" x-bind:style="actualStyle(item.row)" aria-hidden="true"></div>

                                {{-- Label beside the bar, after any slip --}}
                                <span class="pointer-events-none absolute top-1/2 flex -translate-y-1/2 items-center whitespace-nowrap text-[11px] leading-4" x-bind:style="labelStyle(item.row)">
                                    <span x-show="isSlipping(item.row)" class="me-1.5 rounded bg-status-critical-soft px-1 font-bold tabular-nums text-status-critical" x-text="'+' + daysLate(item.row) + 'd'"></span>
                                    <span dir="auto" x-bind:class="item.row.summary ? 'font-semibold text-secondary-dark dark:text-ink' : 'text-secondary-medium dark:text-ink-muted'" x-text="barLabel(item.row)"></span>
                                </span>
                            </div>
                        </div>
                    </template>

                    {{-- Dependency arrows and funding routes --}}
                    <svg
                        x-ref="linkLayer"
                        class="pointer-events-none absolute top-0 z-[15] overflow-visible"
                        x-bind:style="`left:${gridWidth}px;width:${chartWidth}px;height:${Math.max(bodyHeight, 1)}px`"
                        aria-hidden="true"
                    >
                        <defs>
                            <marker id="gantt-arrow-{{ $scope }}" viewBox="0 0 8 8" refX="7" refY="4" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
                                <path d="M0,0 L8,4 L0,8 z" class="fill-secondary-dark dark:fill-ink"></path>
                            </marker>
                            <marker id="gantt-arrow-critical-{{ $scope }}" viewBox="0 0 8 8" refX="7" refY="4" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
                                <path d="M0,0 L8,4 L0,8 z" class="fill-status-critical"></path>
                            </marker>
                            <marker id="gantt-arrow-active-{{ $scope }}" viewBox="0 0 8 8" refX="7" refY="4" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
                                <path d="M0,0 L8,4 L0,8 z" class="fill-status-active"></path>
                            </marker>
                            <marker id="gantt-arrow-funding-{{ $scope }}" viewBox="0 0 8 8" refX="7" refY="4" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
                                <path d="M0,0 L8,4 L0,8 z" class="fill-funding"></path>
                            </marker>
                        </defs>
                        {{-- Alpine's <template> cannot live inside SVG, so arrows and routes are drawn as markup. --}}
                        <g x-html="relationsMarkup(@js($scope))"></g>
                        <g x-html="linksMarkup(@js($scope))" x-on:click="onLinkClick($event)"></g>
                        <line
                            x-show="linkDraft !== null"
                            x-bind:x1="linkDraft?.x1 ?? 0"
                            x-bind:y1="linkDraft?.y1 ?? 0"
                            x-bind:x2="linkDraft?.x2 ?? 0"
                            x-bind:y2="linkDraft?.y2 ?? 0"
                            stroke-width="1.5"
                            stroke-dasharray="4 3"
                            class="stroke-primary"
                            marker-end="url(#gantt-arrow-critical-{{ $scope }})"
                        ></line>
                    </svg>

                    <template x-if="visibleRows.length === 0">
                        <div class="sticky left-0 flex flex-col items-start gap-2 p-6" x-bind:style="`width:${Math.min(viewportWidth, 480)}px`">
                            <p class="text-sm font-semibold text-secondary-dark dark:text-ink">{{ __('gantt.nothing_matches') }}</p>
                            <button type="button" x-on:click="clearFilters()" class="text-xs font-semibold text-secondary-medium underline underline-offset-4 hover:text-secondary-dark dark:text-ink-muted dark:hover:text-ink">{{ __('gantt.clear_filters') }}</button>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        @if ($explorer)
            {{-- Inspector: everything about the selected row and everything it connects to --}}
            <aside
                data-gantt-inspector
                x-show="inspected !== null"
                x-cloak
                x-transition:enter="transition duration-200 ease-out"
                x-transition:enter-start="opacity-0 translate-x-4 rtl:-translate-x-4 max-lg:translate-x-0 max-lg:translate-y-6"
                x-transition:enter-end="opacity-100 translate-x-0 max-lg:translate-y-0"
                x-transition:leave="transition duration-150 ease-in"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="gantt-no-print z-40 flex flex-col overflow-y-auto overscroll-contain bg-white max-lg:fixed max-lg:inset-x-0 max-lg:bottom-0 max-lg:max-h-[78vh] max-lg:rounded-t-2xl max-lg:border-t max-lg:border-board-line max-lg:shadow-[0_-12px_32px_rgba(16,24,40,0.18)] lg:w-[23rem] lg:shrink-0 lg:border-s lg:border-board-line dark:bg-surface-1"
                aria-label="{{ __('gantt.inspector.label') }}"
            >
                <template x-if="inspected">
                    <div class="flex flex-col">
                        {{-- Identity --}}
                        <div class="sticky top-0 z-10 flex flex-col gap-2.5 border-b border-board-line bg-white px-4 pt-3.5 pb-3.5 dark:bg-surface-1">
                            <div class="flex items-start gap-2">
                                <nav class="flex min-h-7 min-w-0 flex-1 flex-wrap items-center gap-x-1 gap-y-0.5 text-[11px] text-secondary-medium dark:text-ink-muted" aria-label="{{ __('gantt.inspector.breadcrumb') }}">
                                    <template x-for="ancestor in inspected.ancestors" :key="ancestor.id">
                                        <span class="flex min-w-0 items-center gap-1">
                                            <button type="button" x-on:click="selectRow(ancestor.id)" class="max-w-40 truncate hover:text-secondary-dark hover:underline dark:hover:text-ink" dir="auto" x-text="ancestor.name"></button>
                                            <flux:icon.chevron-right variant="micro" class="size-3 shrink-0 text-secondary-light rtl:rotate-180" />
                                        </span>
                                    </template>
                                </nav>
                                <button type="button" x-on:click="clearSelection()" class="grid size-7 shrink-0 place-items-center rounded-lg text-secondary-medium hover:bg-board-head hover:text-secondary-dark dark:text-ink-muted dark:hover:bg-white/10 dark:hover:text-ink" aria-label="{{ __('gantt.inspector.close') }}" title="{{ __('gantt.inspector.close') }} (Esc)">
                                    <flux:icon.x-mark variant="micro" />
                                </button>
                            </div>

                            <h3 class="text-lg leading-snug font-bold text-balance text-secondary-dark dark:text-ink" dir="auto" x-text="inspected.row.name"></h3>

                            <div class="flex flex-wrap items-center gap-1.5">
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-board-head px-2 py-0.5 text-[11px] font-semibold text-secondary-medium dark:bg-white/10 dark:text-ink-muted">
                                    @foreach ($icons as $iconKey => [$iconName, $iconTint])
                                        <flux:icon :name="$iconName" variant="micro" class="size-3 {{ $iconTint }}" x-show="iconKey(inspected.row) === '{{ $iconKey }}'" />
                                    @endforeach
                                    <span x-text="labels.kinds[inspected.row.kind] ?? inspected.row.kind"></span>
                                    <span class="tabular-nums text-secondary-light" x-text="inspected.row.wbs"></span>
                                </span>
                                <span x-show="inspected.row.statusLabel" class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset" x-bind:class="tones.pill[inspected.row.tone]" x-text="inspected.row.statusLabel"></span>
                                <span x-show="inspected.row.critical" class="inline-flex items-center gap-1 rounded-full bg-status-critical-soft px-2 py-0.5 text-[11px] font-semibold text-status-critical">
                                    <span class="size-1.5 rotate-45 bg-status-critical" aria-hidden="true"></span>{{ __('gantt.critical') }}
                                </span>
                                <span x-show="isSlipping(inspected.row)" class="inline-flex rounded-full bg-status-critical-soft px-2 py-0.5 text-[11px] font-semibold tabular-nums text-status-critical" x-text="@js(__('gantt.inspector.days_late')).replace(':days', daysLate(inspected.row))"></span>
                            </div>
                        </div>

                        {{-- Progress against time --}}
                        <template x-if="inspected.row.kind !== 'group' && inspected.row.startDay !== null">
                            <section class="flex flex-col gap-2 border-b border-board-line px-4 py-3.5" aria-label="{{ __('gantt.progress') }}">
                                <div class="flex items-baseline justify-between gap-3">
                                    <p class="text-2xl font-bold tabular-nums text-secondary-dark dark:text-ink"><span x-text="inspected.row.progress"></span><span class="text-sm font-semibold text-secondary-medium dark:text-ink-muted">%</span></p>
                                    <p class="type-meta text-secondary-medium dark:text-ink-muted">
                                        <span class="tabular-nums" x-text="elapsed(inspected.row) + '%'"></span> {{ __('gantt.signals.time_elapsed') }}
                                    </p>
                                </div>
                                <div class="relative h-2 rounded-full" x-bind:class="tones.track[inspected.row.tone]" aria-hidden="true">
                                    <div class="absolute inset-y-0 start-0 rounded-full" x-bind:class="tones.bar[inspected.row.tone]" x-bind:style="`width:${inspected.row.progress}%`"></div>
                                    <div class="absolute -top-1 h-4 w-0.5 rounded-full bg-status-active" x-bind:style="`inset-inline-start:${elapsed(inspected.row)}%`"></div>
                                </div>
                            </section>
                        </template>

                        {{-- Schedule --}}
                        <section class="border-b border-board-line px-4 py-3.5" aria-label="{{ __('gantt.inspector.schedule') }}">
                            <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-xs">
                                <div>
                                    <dt class="{{ $inspectorLabel }}">{{ __('gantt.start') }}</dt>
                                    <dd class="mt-0.5 font-semibold tabular-nums text-secondary-dark dark:text-ink" x-text="formatDay(inspected.row.startDay)"></dd>
                                </div>
                                <div>
                                    <dt class="{{ $inspectorLabel }}">{{ __('gantt.finish') }}</dt>
                                    <dd class="mt-0.5 font-semibold tabular-nums text-secondary-dark dark:text-ink" x-text="formatDay(inspected.row.endDay)"></dd>
                                </div>
                                <div x-show="inspected.row.kind !== 'group'">
                                    <dt class="{{ $inspectorLabel }}">{{ __('gantt.working_days') }}</dt>
                                    <dd class="mt-0.5 font-semibold tabular-nums text-secondary-dark dark:text-ink" x-text="duration(inspected.row)"></dd>
                                </div>
                                <div x-show="inspected.row.actualStartDay !== null || inspected.row.actualEndDay !== null">
                                    <dt class="{{ $inspectorLabel }}">{{ __('gantt.actual') }}</dt>
                                    <dd class="mt-0.5 font-semibold tabular-nums text-secondary-dark dark:text-ink" x-text="formatDay(inspected.row.actualStartDay) + ' → ' + formatDay(inspected.row.actualEndDay)"></dd>
                                </div>
                                <div x-show="inspected.row.float !== null && inspected.row.float !== undefined">
                                    <dt class="{{ $inspectorLabel }}">{{ __('gantt.total_float') }}</dt>
                                    <dd class="mt-0.5 font-semibold tabular-nums" x-bind:class="inspected.row.float <= 0 ? 'text-status-critical' : 'text-secondary-dark dark:text-ink'" x-text="inspected.row.float + ' ' + @js(__('gantt.days'))"></dd>
                                </div>
                                <div x-show="inspected.row.owner" class="col-span-2">
                                    <dt class="{{ $inspectorLabel }}" x-text="inspected.row.kind === 'proposal' ? @js(__('gantt.inspector.donors')) : @js(__('gantt.owner'))"></dt>
                                    <dd class="mt-1 flex items-center gap-2 font-semibold text-secondary-dark dark:text-ink">
                                        <span x-show="inspected.row.kind !== 'proposal'" class="grid size-6 place-items-center rounded-full bg-secondary-soft text-[10px] font-bold dark:bg-white/15" x-text="initials(inspected.row.owner)" aria-hidden="true"></span>
                                        <span dir="auto" x-text="inspected.row.owner"></span>
                                    </dd>
                                </div>
                                <div x-show="inspected.row.money" class="col-span-2">
                                    <dt class="{{ $inspectorLabel }}">{{ __('gantt.inspector.money') }}</dt>
                                    <dd class="mt-0.5 font-bold tabular-nums text-funding" x-text="inspected.row.money"></dd>
                                </div>
                                <div x-show="inspected.row.program" class="col-span-2">
                                    <dt class="{{ $inspectorLabel }}">{{ __('common.program') }}</dt>
                                    <dd class="mt-0.5 font-semibold text-secondary-dark dark:text-ink" dir="auto" x-text="inspected.row.program"></dd>
                                </div>
                            </dl>
                        </section>

                        {{-- What it contains --}}
                        <template x-if="inspected.parts.total > 0">
                            <section class="border-b border-board-line px-4 py-3.5" aria-label="{{ __('gantt.inspector.contains') }}">
                                <div class="flex items-baseline justify-between gap-2">
                                    <h4 class="{{ $inspectorLabel }}">{{ __('gantt.inspector.contains') }}</h4>
                                    <p class="text-[11px] tabular-nums text-secondary-medium dark:text-ink-muted">
                                        <span x-text="inspected.parts.done"></span>/<span x-text="inspected.parts.total"></span> {{ __('gantt.inspector.done') }}
                                        <template x-if="inspected.parts.late > 0">
                                            <span class="font-semibold text-status-critical"> · <span x-text="inspected.parts.late"></span> {{ __('gantt.signals.late') }}</span>
                                        </template>
                                    </p>
                                </div>
                                <ul class="mt-2 flex flex-col">
                                    <template x-for="child in inspected.children" :key="child.id">
                                        <li>
                                            <button type="button" x-on:click="selectRow(child.id)" class="flex w-full items-center gap-2 rounded-lg px-1.5 py-1 text-start text-xs hover:bg-board-head dark:hover:bg-white/10">
                                                <span class="size-2 shrink-0 rounded-full" x-bind:class="[tones.bar[child.tone], child.diamond ? 'rotate-45 rounded-[1px]' : '']" aria-hidden="true"></span>
                                                <span class="min-w-0 flex-1 truncate text-secondary-dark dark:text-ink" dir="auto" x-text="child.name"></span>
                                                <span class="shrink-0 tabular-nums text-secondary-medium dark:text-ink-muted" x-text="formatShort(child.endDay)"></span>
                                            </button>
                                        </li>
                                    </template>
                                </ul>
                                <p x-show="inspected.moreChildren > 0" class="mt-1 px-1.5 text-[11px] text-secondary-medium dark:text-ink-muted" x-text="@js(__('gantt.inspector.more')).replace(':count', inspected.moreChildren)"></p>
                            </section>
                        </template>

                        {{-- Its route: what drives it, what follows it --}}
                        <section class="border-b border-board-line px-4 py-3.5" aria-label="{{ __('gantt.inspector.route') }}">
                            <div class="flex items-baseline justify-between gap-2">
                                <h4 class="{{ $inspectorLabel }}">{{ __('gantt.inspector.route') }}</h4>
                                <button type="button" x-on:click="trace = ! trace" class="text-[11px] font-semibold text-secondary-medium hover:text-secondary-dark dark:text-ink-muted dark:hover:text-ink" x-bind:aria-pressed="trace" x-text="trace ? @js(__('gantt.inspector.tracing_on')) : @js(__('gantt.inspector.tracing_off'))"></button>
                            </div>
                            <p class="mt-1 text-xs text-secondary-medium dark:text-ink-muted" x-show="inspected.upstream + inspected.downstream > 0">
                                <span class="font-semibold tabular-nums text-status-active" x-text="inspected.upstream"></span> {{ __('gantt.inspector.upstream') }}
                                <span aria-hidden="true">·</span>
                                <span class="font-semibold tabular-nums text-status-active" x-text="inspected.downstream"></span> {{ __('gantt.inspector.downstream') }}
                            </p>

                            <template x-if="inspected.drivers.length > 0">
                                <div class="mt-2.5">
                                    <p class="text-[11px] font-semibold text-secondary-medium dark:text-ink-muted">{{ __('gantt.inspector.driven_by') }}</p>
                                    <ul class="mt-1 flex flex-col">
                                        <template x-for="entry in inspected.drivers" :key="'driver-' + entry.row.id">
                                            <li>
                                                <button type="button" x-on:click="selectRow(entry.row.id)" class="flex w-full items-center gap-2 rounded-lg px-1.5 py-1 text-start text-xs hover:bg-board-head dark:hover:bg-white/10">
                                                    <flux:icon.arrow-long-left variant="micro" class="size-3.5 shrink-0 text-status-active rtl:rotate-180" />
                                                    <span class="min-w-0 flex-1 truncate text-secondary-dark dark:text-ink" dir="auto" x-text="entry.row.name"></span>
                                                    <span class="shrink-0 rounded bg-status-active-soft px-1 text-[10px] font-bold tabular-nums text-status-active" x-text="entry.code" x-bind:title="entry.label"></span>
                                                </button>
                                            </li>
                                        </template>
                                    </ul>
                                </div>
                            </template>

                            <template x-if="inspected.dependents.length > 0">
                                <div class="mt-2.5">
                                    <p class="text-[11px] font-semibold text-secondary-medium dark:text-ink-muted">{{ __('gantt.inspector.drives') }}</p>
                                    <ul class="mt-1 flex flex-col">
                                        <template x-for="entry in inspected.dependents" :key="'dependent-' + entry.row.id">
                                            <li>
                                                <button type="button" x-on:click="selectRow(entry.row.id)" class="flex w-full items-center gap-2 rounded-lg px-1.5 py-1 text-start text-xs hover:bg-board-head dark:hover:bg-white/10">
                                                    <flux:icon.arrow-long-right variant="micro" class="size-3.5 shrink-0 text-status-active rtl:rotate-180" />
                                                    <span class="min-w-0 flex-1 truncate text-secondary-dark dark:text-ink" dir="auto" x-text="entry.row.name"></span>
                                                    <span class="shrink-0 rounded bg-status-active-soft px-1 text-[10px] font-bold tabular-nums text-status-active" x-text="entry.code" x-bind:title="entry.label"></span>
                                                </button>
                                            </li>
                                        </template>
                                    </ul>
                                </div>
                            </template>

                            <p x-show="inspected.drivers.length === 0 && inspected.dependents.length === 0 && inspected.upstream + inspected.downstream === 0" class="mt-1 text-xs text-secondary-medium dark:text-ink-muted">{{ __('gantt.inspector.no_links') }}</p>
                        </section>

                        {{-- The money around it --}}
                        <template x-if="inspected.proposals.length + inspected.funded.length + inspected.obligations.length > 0">
                            <section class="border-b border-board-line px-4 py-3.5" aria-label="{{ __('gantt.inspector.funding') }}">
                                <h4 class="{{ $inspectorLabel }}">{{ __('gantt.inspector.funding') }}</h4>
                                @foreach (['proposals' => __('gantt.inspector.funded_by'), 'funded' => __('gantt.inspector.funds_projects'), 'obligations' => __('gantt.inspector.obligations')] as $list => $listLabel)
                                    <template x-if="inspected.{{ $list }}.length > 0">
                                        <div class="mt-2.5">
                                            <p class="text-[11px] font-semibold text-secondary-medium dark:text-ink-muted">{{ $listLabel }}</p>
                                            <ul class="mt-1 flex flex-col">
                                                <template x-for="entry in inspected.{{ $list }}" :key="'{{ $list }}-' + entry.id">
                                                    <li>
                                                        <button type="button" x-on:click="selectRow(entry.id)" class="flex w-full items-center gap-2 rounded-lg px-1.5 py-1 text-start text-xs hover:bg-board-head dark:hover:bg-white/10">
                                                            <span class="size-2 shrink-0 rounded-full" x-bind:class="[tones.bar[entry.tone], entry.diamond ? 'rotate-45 rounded-[1px]' : '']" aria-hidden="true"></span>
                                                            <span class="min-w-0 flex-1 truncate text-secondary-dark dark:text-ink" dir="auto" x-text="entry.name"></span>
                                                            <span class="shrink-0 text-end tabular-nums">
                                                                <span x-show="entry.money" class="block font-semibold text-funding" x-text="entry.money"></span>
                                                                <span class="block text-[11px] text-secondary-medium dark:text-ink-muted" x-text="entry.diamond ? formatDay(entry.startDay) : formatShort(entry.startDay) + ' – ' + formatShort(entry.endDay)"></span>
                                                            </span>
                                                        </button>
                                                    </li>
                                                </template>
                                            </ul>
                                        </div>
                                    </template>
                                @endforeach
                            </section>
                        </template>

                        {{-- Actions --}}
                        <div class="mt-auto flex flex-wrap items-center gap-2 px-4 py-3.5">
                            <template x-if="inspected.row.url">
                                <button type="button" x-on:click="open(inspected.row)" class="inline-flex items-center gap-1.5 rounded-lg bg-secondary-dark px-3 py-2 text-xs font-semibold text-white hover:bg-primary dark:bg-ink dark:text-surface-0 dark:hover:bg-ink/85">
                                    <flux:icon.arrow-top-right-on-square variant="micro" class="size-3.5" />{{ __('gantt.inspector.open_record') }}
                                </button>
                            </template>
                            <template x-if="inspected.row.planUrl">
                                <button type="button" x-on:click="openPlan(inspected.row)" class="inline-flex items-center gap-1.5 rounded-lg border border-secondary-soft px-3 py-2 text-xs font-semibold text-secondary-dark hover:border-secondary-light dark:border-white/15 dark:text-ink">
                                    <flux:icon.presentation-chart-bar variant="micro" class="size-3.5" />{{ __('gantt.inspector.open_plan') }}
                                </button>
                            </template>
                            <button type="button" x-show="inspected.row.startDay !== null" x-on:click="zoomToRow(inspected.row)" class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-2 text-xs font-semibold text-secondary-medium hover:bg-board-head hover:text-secondary-dark dark:text-ink-muted dark:hover:bg-white/10 dark:hover:text-ink">
                                <flux:icon.viewfinder-circle variant="micro" class="size-3.5" />{{ __('gantt.inspector.zoom_to') }}
                            </button>
                        </div>
                    </div>
                </template>
            </aside>
        @endif
    </div>

    {{-- Status bar: what is showing, what is traced, and the key --}}
    <div class="flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-board-line px-4 py-2.5 type-meta text-secondary-medium dark:text-ink-muted">
        <span class="tabular-nums">
            <span class="font-semibold text-secondary-dark dark:text-ink" x-text="visibleRows.length"></span> / <span x-text="rows.length"></span> {{ __('gantt.rows') }}
        </span>
        <template x-if="filtering">
            <button type="button" x-on:click="clearFilters()" class="gantt-no-print inline-flex items-center gap-1 font-semibold text-secondary-dark hover:underline dark:text-ink">
                <flux:icon.x-mark variant="micro" class="size-3" />{{ __('gantt.clear_filters') }}
            </button>
        </template>
        @if ($explorer)
            <template x-if="traced">
                <span class="gantt-no-print inline-flex min-w-0 items-center gap-1.5 text-status-active">
                    <span class="size-1.5 rounded-full bg-status-active" aria-hidden="true"></span>
                    <span class="max-w-56 truncate font-semibold" dir="auto" x-text="rowName(selectedId)"></span>
                    <span class="text-secondary-medium dark:text-ink-muted" x-text="@js(__('gantt.connected_count')).replace(':count', Math.max(0, traced.roles.size - 1))"></span>
                </span>
            </template>
        @endif

        <div class="ms-auto flex flex-wrap items-center gap-x-4 gap-y-1.5" aria-hidden="true">
            <span class="inline-flex items-center gap-1.5"><span class="relative h-2.5 w-6 overflow-hidden rounded-[3px] bg-secondary-soft/80 dark:bg-white/15"><span class="absolute inset-y-0 left-0 w-3 bg-secondary-medium dark:bg-ink-muted"></span></span>{{ __('gantt.legend.planned') }}</span>
            <span class="inline-flex items-center gap-1.5"><span class="h-1.5 w-6 rounded-sm bg-secondary-dark/80 dark:bg-white/80"></span>{{ __('gantt.legend.summary') }}</span>
            <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rotate-45 rounded-[2px] bg-secondary-medium"></span>{{ __('gantt.legend.milestone') }}</span>
            <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-4 rounded-[3px] bg-funding"></span>{{ __('gantt.legend.funding') }}</span>
            <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-4 rounded-[3px] ring-2 ring-status-critical"></span>{{ __('gantt.legend.critical') }}</span>
            <span class="inline-flex items-center gap-1.5"><span class="gantt-slip h-2 w-5 rounded-e-[3px]"></span>{{ __('gantt.legend.late') }}</span>
            @if ($explorer)
                <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-4 rounded-[3px] ring-2 ring-status-active"></span>{{ __('gantt.legend.route') }}</span>
            @endif
            <span class="inline-flex items-center gap-1.5"><span class="h-1 w-6 rounded-full bg-secondary-dark/70 dark:bg-white/70"></span>{{ __('gantt.legend.actual') }}</span>
            <span class="inline-flex items-center gap-1.5" x-show="hasBaseline"><span class="gantt-baseline h-1.5 w-6 rounded-sm"></span>{{ __('gantt.legend.baseline') }}</span>
        </div>
    </div>

    {{-- Link editor --}}
    @if ($editable)
        <template x-teleport="body">
            <div
                x-show="linkMenu !== null"
                x-cloak
                x-on:click.stop
                x-on:keydown.escape.window="linkMenu = null"
                class="fixed z-[70] w-64 rounded-xl border border-secondary-soft bg-white p-3 text-xs shadow-xl dark:border-white/15 dark:bg-surface-3 dark:shadow-raised"
                x-bind:style="linkMenuStyle()"
                role="dialog"
                aria-label="{{ __('gantt.edit_link') }}"
            >
                <template x-if="linkMenu">
                    <div class="flex flex-col gap-2.5">
                        <p class="font-semibold text-secondary-dark dark:text-ink">{{ __('gantt.edit_link') }}</p>
                        <p class="text-secondary-medium dark:text-ink-muted" dir="auto" x-text="rowName(linkMenu.link.from) + ' → ' + rowName(linkMenu.link.to)"></p>
                        <label class="flex flex-col gap-1 text-secondary-medium dark:text-ink-muted">
                            {{ __('gantt.link_type') }}
                            <select x-model="linkMenu.type" class="{{ $control }}">
                                @foreach ($linkTypes as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="flex flex-col gap-1 text-secondary-medium dark:text-ink-muted">
                            {{ __('gantt.lag') }}
                            <input type="number" min="-365" max="365" step="1" x-model="linkMenu.lag" class="{{ $control }}" x-on:keydown.enter.prevent="saveLinkMenu()">
                        </label>
                        <div class="flex items-center justify-between gap-2 pt-1">
                            <button type="button" x-on:click="deleteLinkMenu()" class="font-semibold text-status-critical hover:underline">{{ __('gantt.delete_link') }}</button>
                            <div class="flex items-center gap-2">
                                <button type="button" x-on:click="linkMenu = null" class="font-semibold text-secondary-medium hover:text-secondary-dark dark:text-ink-muted dark:hover:text-ink">{{ __('gantt.cancel') }}</button>
                                <button type="button" x-on:click="saveLinkMenu()" class="rounded-lg bg-secondary-dark px-2.5 py-1 font-semibold text-white hover:bg-primary dark:hover:bg-ink/85 dark:bg-ink dark:text-surface-0">{{ __('gantt.save') }}</button>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </template>
    @endif

    {{-- Tooltip --}}
    <template x-teleport="body">
        <div
            x-show="hover !== null"
            x-cloak
            class="pointer-events-none fixed z-[60] w-[19rem] rounded-xl border border-secondary-soft bg-white p-3.5 text-xs shadow-[0_12px_32px_rgba(16,24,40,0.16),0_2px_6px_rgba(16,24,40,0.06)] dark:border-white/15 dark:bg-surface-3 dark:shadow-raised"
            x-bind:style="tipStyle()"
        >
            <template x-if="hover">
                <div class="flex flex-col gap-2">
                    <div class="flex items-center gap-1.5 text-[11px] font-semibold text-secondary-medium dark:text-ink-muted">
                        <span x-text="labels.kinds[hover.row.kind] ?? ''"></span>
                        <span aria-hidden="true">·</span>
                        <span class="tabular-nums" x-text="hover.row.wbs"></span>
                    </div>
                    <p class="text-sm leading-snug font-bold text-secondary-dark dark:text-ink" dir="auto" x-text="hover.row.name"></p>
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span x-show="hover.row.statusLabel" class="inline-flex rounded-full px-2 py-0.5 text-[10.5px] font-semibold ring-1 ring-inset" x-bind:class="tones.pill[hover.row.tone]" x-text="hover.row.statusLabel"></span>
                        <span x-show="hover.row.critical" class="inline-flex rounded-full bg-status-critical-soft px-2 py-0.5 text-[10.5px] font-semibold text-status-critical">{{ __('gantt.critical') }}</span>
                    </div>
                    <div class="relative h-1.5 rounded-full" x-bind:class="tones.track[hover.row.tone]" aria-hidden="true">
                        <div class="absolute inset-y-0 start-0 rounded-full" x-bind:class="tones.bar[hover.row.tone]" x-bind:style="`width:${hover.row.progress}%`"></div>
                    </div>
                    <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 tabular-nums">
                        <dt class="text-secondary-medium dark:text-ink-muted">{{ __('gantt.planned') }}</dt>
                        <dd class="text-secondary-dark dark:text-ink" x-text="formatDay(hover.row.startDay) + ' → ' + formatDay(hover.row.endDay)"></dd>
                        <template x-if="hover.row.actualStartDay !== null || hover.row.actualEndDay !== null">
                            <dt class="text-secondary-medium dark:text-ink-muted">{{ __('gantt.actual') }}</dt>
                        </template>
                        <template x-if="hover.row.actualStartDay !== null || hover.row.actualEndDay !== null">
                            <dd class="text-secondary-dark dark:text-ink" x-text="formatDay(hover.row.actualStartDay) + ' → ' + formatDay(hover.row.actualEndDay)"></dd>
                        </template>
                        <template x-if="hasBaseline && hover.row.baselineStartDay !== null">
                            <dt class="text-secondary-medium dark:text-ink-muted">{{ __('gantt.baseline') }}</dt>
                        </template>
                        <template x-if="hasBaseline && hover.row.baselineStartDay !== null">
                            <dd class="text-secondary-dark dark:text-ink" x-text="formatDay(hover.row.baselineStartDay) + ' → ' + formatDay(hover.row.baselineEndDay)"></dd>
                        </template>
                        <dt class="text-secondary-medium dark:text-ink-muted">{{ __('gantt.working_days') }}</dt>
                        <dd class="text-secondary-dark dark:text-ink" x-text="duration(hover.row)"></dd>
                        <dt class="text-secondary-medium dark:text-ink-muted">{{ __('gantt.progress') }}</dt>
                        <dd class="font-semibold text-secondary-dark dark:text-ink" x-text="hover.row.progress + '%'"></dd>
                        <template x-if="hover.row.float !== null && hover.row.float !== undefined">
                            <dt class="text-secondary-medium dark:text-ink-muted">{{ __('gantt.total_float') }}</dt>
                        </template>
                        <template x-if="hover.row.float !== null && hover.row.float !== undefined">
                            <dd class="text-secondary-dark dark:text-ink" x-text="hover.row.float + ' ' + @js(__('gantt.days'))"></dd>
                        </template>
                        <template x-if="hover.row.predecessors">
                            <dt class="text-secondary-medium dark:text-ink-muted">{{ __('gantt.predecessors') }}</dt>
                        </template>
                        <template x-if="hover.row.predecessors">
                            <dd class="text-secondary-dark dark:text-ink" x-text="hover.row.predecessors"></dd>
                        </template>
                        <template x-if="slip(hover.row) > 0">
                            <dt class="text-status-critical">{{ __('gantt.slip') }}</dt>
                        </template>
                        <template x-if="slip(hover.row) > 0">
                            <dd class="font-semibold text-status-critical" x-text="slip(hover.row) + ' ' + @js(__('gantt.days'))"></dd>
                        </template>
                        <template x-if="hover.row.owner">
                            <dt class="text-secondary-medium dark:text-ink-muted" x-text="hover.row.kind === 'proposal' ? @js(__('gantt.inspector.donors')) : @js(__('gantt.owner'))"></dt>
                        </template>
                        <template x-if="hover.row.owner">
                            <dd class="text-secondary-dark dark:text-ink" dir="auto" x-text="hover.row.owner"></dd>
                        </template>
                        <template x-if="hover.row.money">
                            <dt class="text-secondary-medium dark:text-ink-muted">{{ __('gantt.inspector.money') }}</dt>
                        </template>
                        <template x-if="hover.row.money">
                            <dd class="font-semibold text-funding" x-text="hover.row.money"></dd>
                        </template>
                    </dl>
                    @if ($explorer)
                        <p class="border-t border-board-line pt-2 text-[11px] text-secondary-medium dark:text-ink-muted">{{ __('gantt.tip_hint') }}</p>
                    @endif
                </div>
            </template>
        </div>
    </template>
</div>
