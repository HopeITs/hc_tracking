@props(['payload', 'scope' => 'default', 'editable' => false, 'height' => 'h-[min(72vh,46rem)]'])

{{--
    The Gantt engine's shell. All layout and interaction lives in the
    ganttChart Alpine component (resources/js/gantt/index.js); this view only
    lays out the toolbar, split grid/timeline, link layer and popovers it
    binds to.

    Pass `editable` only where the Livewire page mixes in EditsProjectGantt —
    every edit calls one of its gantt* actions through $wire. Wrap the
    component in wire:ignore (the chart redraws from each action's answer).

    The chart area is forced LTR: bars are positioned by pixel offset from the
    window start, and time reads left-to-right in both locales, as MS Project
    does. Row text keeps dir="auto" so Arabic names still read correctly.
--}}

@php
    $toneBar = [
        'neutral' => 'bg-secondary-medium dark:bg-secondary-light',
        'good' => 'bg-status-good',
        'warn' => 'bg-status-warn',
        'critical' => 'bg-status-critical',
        'funding' => 'bg-funding',
        'muted' => 'bg-secondary-soft dark:bg-secondary-medium',
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
        ],
    ];
    $toolButton = 'grid size-7 place-items-center rounded-md text-secondary-dark hover:bg-board disabled:opacity-35 dark:text-white dark:hover:bg-secondary-medium';
    $chip = 'inline-flex cursor-pointer items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold transition-colors';
    $chipOn = 'border-secondary-dark bg-secondary-dark text-white dark:border-white dark:bg-white dark:text-secondary-dark';
    $chipOff = 'border-secondary-soft text-secondary-medium hover:text-secondary-dark dark:border-secondary-medium dark:text-secondary-soft dark:hover:text-white';
    $control = 'rounded-md border border-secondary-soft bg-white px-2 py-1 text-xs text-secondary-dark dark:border-secondary-medium dark:bg-secondary-dark dark:text-white';
@endphp

<div
    {{ $attributes->class('gantt flex flex-col') }}
    x-data="ganttChart(@js($payload), @js($scope), @js((bool) $editable), @js($labels))"
    x-on:resize.window.debounce.150ms="measure()"
    x-on:click.window="linkMenu = null"
    data-gantt
>
    {{-- Toolbar: navigation and editing --}}
    <div class="gantt-toolbar flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-board-line px-3.5 py-2.5">
        <div class="inline-flex rounded-lg border border-secondary-soft p-0.5 dark:border-secondary-medium" role="group" aria-label="{{ __('gantt.zoom') }}">
            @foreach ($zoomLevels as $level => $levelLabel)
                <button
                    type="button"
                    x-on:click="setZoom('{{ $level }}')"
                    x-bind:aria-pressed="zoom === '{{ $level }}'"
                    x-bind:class="zoom === '{{ $level }}' ? 'bg-secondary-dark text-white dark:bg-white dark:text-secondary-dark' : 'text-secondary-medium hover:text-secondary-dark dark:text-secondary-soft dark:hover:text-white'"
                    class="rounded-md px-2.5 py-1 text-xs font-semibold transition-colors"
                >{{ $levelLabel }}</button>
            @endforeach
        </div>

        <div class="flex items-center gap-3 text-xs font-semibold text-secondary-medium dark:text-secondary-soft">
            <button type="button" x-on:click="goToToday()" class="inline-flex items-center gap-1.5 text-secondary-dark hover:text-primary dark:text-white">
                <span class="size-2 rounded-full bg-primary" aria-hidden="true"></span>{{ __('ui.today') }}
            </button>
            <button type="button" x-on:click="zoomToFit()" class="hover:text-secondary-dark dark:hover:text-white">{{ __('gantt.fit') }}</button>
            <span class="size-1 rounded-full bg-secondary-light" aria-hidden="true"></span>
            <button type="button" x-on:click="expandAll()" class="hover:text-secondary-dark dark:hover:text-white">{{ __('gantt.expand_all') }}</button>
            <button type="button" x-on:click="collapseAll()" class="hover:text-secondary-dark dark:hover:text-white">{{ __('gantt.collapse_all') }}</button>
        </div>

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
                <span x-show="busy" x-cloak class="ms-1.5 inline-flex items-center gap-1.5 text-xs text-secondary-medium dark:text-secondary-soft">
                    <span class="size-1.5 animate-pulse rounded-full bg-primary"></span>{{ __('gantt.saving') }}
                </span>
            </div>
        @endif

        <div class="ms-auto flex items-center gap-2">
            <input
                type="search"
                x-model.debounce.150ms="search"
                placeholder="{{ __('gantt.search') }}"
                aria-label="{{ __('gantt.search') }}"
                class="w-44 {{ $control }} placeholder:text-secondary-light"
            >
            <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false">
                <button type="button" x-on:click="open = ! open" class="inline-flex items-center gap-1.5 {{ $control }} font-semibold" x-bind:aria-expanded="open">
                    <flux:icon.arrow-down-tray variant="micro" class="size-3.5" />{{ __('gantt.export') }}
                </button>
                <div x-show="open" x-cloak x-transition.opacity class="absolute end-0 top-full z-40 mt-1 w-48 rounded-lg border border-secondary-soft bg-white p-1 shadow-lg dark:border-secondary-medium dark:bg-secondary-dark">
                    <button type="button" x-on:click="open = false; exportCsv()" class="block w-full rounded-md px-2.5 py-1.5 text-start text-xs text-secondary-dark hover:bg-board dark:text-white dark:hover:bg-secondary-medium">{{ __('gantt.export_csv') }}</button>
                    <button type="button" x-on:click="open = false; printChart()" class="block w-full rounded-md px-2.5 py-1.5 text-start text-xs text-secondary-dark hover:bg-board dark:text-white dark:hover:bg-secondary-medium">{{ __('gantt.export_pdf') }}</button>
                </div>
            </div>
        </div>
    </div>

    {{-- Toolbar: what to show --}}
    <div class="gantt-toolbar flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-board-line px-3.5 py-2">
        <button type="button" x-on:click="showCritical = ! showCritical" class="{{ $chip }}" x-bind:class="showCritical ? @js($chipOn) : @js($chipOff)" x-bind:aria-pressed="showCritical">
            <span class="size-2 rounded-full bg-status-critical" aria-hidden="true"></span>{{ __('gantt.critical_path') }}
        </button>
        <button type="button" x-on:click="showLinks = ! showLinks" class="{{ $chip }}" x-bind:class="showLinks ? @js($chipOn) : @js($chipOff)" x-bind:aria-pressed="showLinks">
            {{ __('gantt.links') }}
        </button>
        <button type="button" x-on:click="criticalOnly = ! criticalOnly" class="{{ $chip }}" x-bind:class="criticalOnly ? @js($chipOn) : @js($chipOff)" x-bind:aria-pressed="criticalOnly">
            {{ __('gantt.critical_only') }}
        </button>
        <button type="button" x-on:click="lateOnly = ! lateOnly" class="{{ $chip }}" x-bind:class="lateOnly ? @js($chipOn) : @js($chipOff)" x-bind:aria-pressed="lateOnly">
            {{ __('gantt.late_only') }}
        </button>

        <span class="mx-1 h-4 w-px bg-board-line" aria-hidden="true"></span>

        <label class="inline-flex items-center gap-2 text-xs font-semibold text-secondary-medium dark:text-secondary-soft">
            {{ __('gantt.weekend') }}
            <select x-bind:value="weekendPreset" x-on:change="changeWeekend($event.target.value)" x-bind:disabled="busy" class="{{ $control }}">
                @foreach ($weekendPresets as $preset => $presetLabel)
                    <option value="{{ $preset }}">{{ $presetLabel }}</option>
                @endforeach
            </select>
        </label>

        @if ($editable)
            <span class="mx-1 h-4 w-px bg-board-line" aria-hidden="true"></span>

            <div class="flex flex-wrap items-center gap-2 text-xs font-semibold text-secondary-medium dark:text-secondary-soft">
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
                <input type="text" x-model="baselineName" maxlength="255" placeholder="{{ __('gantt.baseline_name') }}" aria-label="{{ __('gantt.baseline_name') }}" class="w-36 {{ $control }} placeholder:text-secondary-light" x-on:keydown.enter.prevent="saveBaseline()">
                <button type="button" x-on:click="saveBaseline()" x-bind:disabled="busy" class="rounded-md bg-secondary-dark px-2.5 py-1 text-xs font-semibold text-white hover:bg-primary disabled:opacity-40 dark:bg-white dark:text-secondary-dark">{{ __('gantt.save_baseline') }}</button>
            </div>
        @endif
    </div>

    {{-- Split grid + timeline, one scroller so both halves share vertical scroll --}}
    <div
        x-ref="scroller"
        dir="ltr"
        tabindex="0"
        role="treegrid"
        aria-label="{{ __('gantt.chart') }}"
        x-on:scroll="scrollTop = $el.scrollTop"
        x-on:wheel="onWheel($event)"
        x-on:keydown="onKeydown($event)"
        class="gantt-scroller relative {{ $height }} overflow-auto overscroll-contain focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
    >
        <div class="relative" x-bind:style="`width:${gridWidth + chartWidth}px`">
            {{-- Header --}}
            <div class="sticky top-0 z-30 flex h-14 border-b border-board-line bg-white dark:bg-secondary-dark">
                <div class="sticky left-0 z-10 flex shrink-0 items-end border-e border-board-line bg-white pb-2 type-label text-secondary-medium dark:bg-secondary-dark dark:text-secondary-soft" x-bind:style="`width:${gridWidth}px`">
                    <div class="grid w-full items-center gap-2 overflow-hidden px-3" x-bind:style="gridTemplate">
                        <span>{{ __('gantt.wbs') }}</span>
                        <span>{{ __('gantt.task_name') }}</span>
                        <span class="text-end">{{ __('gantt.start') }}</span>
                        <span class="text-end">{{ __('gantt.finish') }}</span>
                        <span class="text-end" title="{{ __('gantt.working_days') }}">{{ __('gantt.duration_short') }}</span>
                        <span class="text-end">%</span>
                        <span class="truncate" title="{{ __('gantt.predecessors') }}">{{ __('gantt.predecessors_short') }}</span>
                        <span class="text-end" x-show="hasBaseline" title="{{ __('gantt.variance') }}">{{ __('gantt.variance_short') }}</span>
                    </div>
                    <span
                        class="absolute inset-y-0 -right-1 w-2 cursor-col-resize"
                        x-on:pointerdown.prevent="startResize($event)"
                        role="separator"
                        aria-orientation="vertical"
                        aria-label="{{ __('gantt.resize_grid') }}"
                    ></span>
                </div>
                <div class="relative shrink-0" x-bind:style="`width:${chartWidth}px`">
                    <template x-for="cell in topTier" :key="cell.key">
                        <div class="absolute top-0 h-7 truncate border-s border-board-line px-2 pt-1.5 text-xs font-semibold text-secondary-dark dark:text-white" x-bind:style="`left:${x(cell.start)}px;width:${cell.days * ppd}px`" x-text="cell.label"></div>
                    </template>
                    <template x-for="cell in bottomTier" :key="cell.key">
                        <div
                            class="absolute bottom-0 h-7 truncate border-s border-t border-board-line pt-1.5 text-center text-[11px] tabular-nums text-secondary-medium dark:text-secondary-soft"
                            x-bind:class="zoom === 'year' ? 'top-0 h-14 pt-5' : ''"
                            x-bind:style="`left:${x(cell.start)}px;width:${cell.days * ppd}px`"
                            x-text="cell.days * ppd >= 18 ? cell.label : ''"
                        ></div>
                    </template>
                    <div class="absolute bottom-0 z-10 -translate-x-1/2 rounded-full bg-primary px-1.5 py-px text-[10px] font-bold text-white" x-bind:style="`left:${x(todayDay) + ppd / 2}px`">{{ __('ui.today') }}</div>
                </div>
            </div>

            {{-- Body --}}
            <div class="relative" x-bind:style="`height:${Math.max(bodyHeight, 1)}px`">
                {{-- Background: weekends, unit lines, today --}}
                <div class="pointer-events-none absolute inset-y-0" x-bind:style="`left:${gridWidth}px;width:${chartWidth}px`" aria-hidden="true">
                    <template x-for="day in weekendStripes" :key="day">
                        <div class="absolute inset-y-0 bg-secondary-soft/30 dark:bg-black/15" x-bind:style="`left:${x(day)}px;width:${ppd}px`"></div>
                    </template>
                    <template x-for="cell in bottomTier" :key="'line-' + cell.key">
                        <div class="absolute inset-y-0 border-s border-board-line/60" x-bind:style="`left:${x(cell.start)}px`"></div>
                    </template>
                    <div class="absolute inset-y-0 w-0.5 bg-primary/70" x-bind:style="`left:${x(todayDay) + ppd / 2}px`"></div>
                </div>

                <template x-for="item in windowed" :key="item.row.id">
                    <div
                        class="group absolute inset-x-0 flex"
                        role="row"
                        x-bind:data-row-id="item.row.id"
                        x-bind:aria-level="item.row.depth + 1"
                        x-bind:aria-expanded="item.row.hasChildren ? String(!collapsed[item.row.id]) : null"
                        x-bind:aria-selected="selectedId === item.row.id"
                        x-bind:style="`top:${item.index * rowHeight}px;height:${rowHeight}px`"
                        x-on:click="selectedId = item.row.id"
                    >
                        {{-- Grid cells --}}
                        <div
                            class="sticky left-0 z-20 grid shrink-0 items-center gap-2 overflow-clip border-e border-b border-board-line px-3 text-xs"
                            x-bind:class="selectedId === item.row.id ? 'bg-secondary-soft/60 dark:bg-secondary-medium/60' : (item.row.kind === 'group' ? 'bg-board' : 'bg-white group-hover:bg-board dark:bg-secondary-dark')"
                            x-bind:style="`width:${gridWidth}px;${gridTemplate}`"
                        >
                            <span class="flex items-center gap-1 tabular-nums text-secondary-medium dark:text-secondary-soft">
                                <span x-show="isCriticalShown(item.row)" class="size-1.5 shrink-0 rounded-full bg-status-critical" aria-hidden="true"></span>
                                <span x-text="item.row.wbs"></span>
                            </span>
                            <span class="flex min-w-0 items-center gap-1" x-bind:style="`padding-left:${item.row.depth * 14}px`">
                                <button
                                    type="button"
                                    class="grid size-4 shrink-0 place-items-center rounded text-secondary-medium hover:text-secondary-dark dark:text-secondary-soft dark:hover:text-white"
                                    x-bind:class="item.row.hasChildren ? '' : 'invisible'"
                                    x-on:click.stop="toggle(item.row)"
                                    x-bind:aria-label="collapsed[item.row.id] ? @js(__('gantt.expand')) : @js(__('gantt.collapse'))"
                                >
                                    <svg viewBox="0 0 12 12" class="size-3 transition-transform" x-bind:class="collapsed[item.row.id] ? '' : 'rotate-90'" aria-hidden="true"><path d="M4 2.5 7.5 6 4 9.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </button>
                                <template x-if="isEditing(item.row, 'name')">
                                    <input type="text" data-gantt-editor dir="auto" maxlength="255" x-model="editing.value" x-on:keydown="onEditorKeydown($event)" x-on:blur="commitEdit()" x-on:click.stop class="gantt-editor min-w-0 flex-1">
                                </template>
                                <span
                                    x-show="!isEditing(item.row, 'name')"
                                    dir="auto"
                                    class="truncate"
                                    x-bind:class="item.row.kind === 'group' ? 'type-label text-secondary-dark dark:text-white' : (item.row.summary ? 'font-semibold text-secondary-dark dark:text-white' : 'text-secondary-dark dark:text-secondary-soft')"
                                    x-text="item.row.name"
                                    x-bind:title="item.row.name"
                                    x-on:dblclick.stop="canEditCell(item.row, 'name') ? startEdit(item.row, 'name') : open(item.row)"
                                ></span>
                                <button
                                    type="button"
                                    x-show="item.row.url && !isEditing(item.row, 'name')"
                                    x-on:click.stop="open(item.row)"
                                    class="gantt-no-print ms-auto hidden size-5 shrink-0 place-items-center rounded text-secondary-medium group-hover:grid hover:text-primary dark:text-secondary-soft"
                                    aria-label="{{ __('gantt.open') }}"
                                ><flux:icon.eye variant="micro" class="size-3.5" /></button>
                            </span>
                            @foreach (['start' => 'startDay', 'end' => 'endDay'] as $field => $dayKey)
                                <span class="relative min-w-0 text-end tabular-nums text-secondary-medium dark:text-secondary-soft" x-bind:class="canEditCell(item.row, '{{ $field }}') ? 'cursor-text hover:text-secondary-dark dark:hover:text-white' : ''" x-on:dblclick.stop="startEdit(item.row, '{{ $field }}')">
                                    <template x-if="isEditing(item.row, '{{ $field }}')">
                                        <input type="date" data-gantt-editor x-model="editing.value" x-on:keydown="onEditorKeydown($event)" x-on:blur="commitEdit()" x-on:click.stop class="gantt-editor absolute end-0 top-1/2 z-30 w-32 -translate-y-1/2 shadow-md">
                                    </template>
                                    <span x-show="!isEditing(item.row, '{{ $field }}')" x-text="formatDay(item.row.{{ $dayKey }})"></span>
                                </span>
                            @endforeach
                            <span class="text-end tabular-nums text-secondary-medium dark:text-secondary-soft" x-text="item.row.kind === 'group' ? '' : duration(item.row)"></span>
                            <span class="relative min-w-0 text-end tabular-nums font-semibold" x-bind:class="(item.row.progress >= 100 ? 'text-status-good' : 'text-secondary-dark dark:text-white') + (canEditCell(item.row, 'progress') ? ' cursor-text' : '')" x-on:dblclick.stop="startEdit(item.row, 'progress')">
                                <template x-if="isEditing(item.row, 'progress')">
                                    <input type="number" min="0" max="100" step="5" data-gantt-editor x-model="editing.value" x-on:keydown="onEditorKeydown($event)" x-on:blur="commitEdit()" x-on:click.stop class="gantt-editor absolute end-0 top-1/2 z-30 w-16 -translate-y-1/2 text-end shadow-md">
                                </template>
                                <span x-show="!isEditing(item.row, 'progress')" x-text="item.row.kind === 'group' ? '' : item.row.progress"></span>
                            </span>
                            <span class="truncate tabular-nums text-secondary-medium dark:text-secondary-soft" x-text="item.row.predecessors ?? ''" x-bind:title="item.row.predecessors ?? ''"></span>
                            <span
                                x-show="hasBaseline"
                                class="text-end tabular-nums font-semibold"
                                x-bind:class="variance(item.row) > 0 ? 'text-status-critical' : (variance(item.row) < 0 ? 'text-status-good' : 'text-secondary-medium dark:text-secondary-soft')"
                                x-text="variance(item.row) === null ? '' : (variance(item.row) > 0 ? '+' : '') + variance(item.row)"
                            ></span>
                        </div>

                        {{-- Timeline lane --}}
                        <div class="relative shrink-0 border-b border-board-line/60" x-bind:style="`width:${chartWidth}px`" x-on:dblclick="open(item.row)">
                            {{-- Baseline (what was agreed), under the planned bar --}}
                            <div class="gantt-baseline absolute top-[26px] h-1.5 rounded-sm" x-bind:style="baselineStyle(item.row)" aria-hidden="true"></div>

                            <template x-if="item.row.diamond && item.row.startDay !== null">
                                <div
                                    class="absolute top-1/2 size-3 -translate-x-1/2 -translate-y-1/2 rotate-45 rounded-[2px] ring-2"
                                    x-bind:class="[@js($toneBar)[item.row.tone], isCriticalShown(item.row) ? 'ring-status-critical' : 'ring-white dark:ring-secondary-dark']"
                                    x-bind:style="diamondStyle(item.row)"
                                    x-bind:data-draggable="canDrag(item.row)"
                                    x-on:pointerdown="beginDrag($event, item.row, 'move')"
                                    x-on:mouseenter="showTip(item.row, $event)"
                                    x-on:mousemove="showTip(item.row, $event)"
                                    x-on:mouseleave="hideTip()"
                                ></div>
                            </template>

                            <template x-if="!item.row.diamond && item.row.summary && item.row.startDay !== null">
                                <div class="absolute top-2.5 h-2" x-bind:style="barStyle(item.row)" x-on:mouseenter="showTip(item.row, $event)" x-on:mousemove="showTip(item.row, $event)" x-on:mouseleave="hideTip()">
                                    <div class="absolute inset-0 rounded-sm" x-bind:class="isCriticalShown(item.row) ? 'bg-status-critical/40' : 'bg-secondary-dark/35 dark:bg-white/35'"></div>
                                    <div class="absolute inset-y-0 left-0 rounded-sm" x-bind:class="isCriticalShown(item.row) ? 'bg-status-critical' : 'bg-secondary-dark dark:bg-white'" x-bind:style="`width:${item.row.progress}%`"></div>
                                    <span class="absolute -bottom-1.5 left-0 border-x-4 border-t-[6px] border-x-transparent border-t-secondary-dark dark:border-t-white" aria-hidden="true"></span>
                                    <span class="absolute right-0 -bottom-1.5 border-x-4 border-t-[6px] border-x-transparent border-t-secondary-dark dark:border-t-white" aria-hidden="true"></span>
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
                                            <span class="absolute top-1.5 -left-1 z-10 h-5 w-2 cursor-ew-resize rounded-sm bg-secondary-dark opacity-0 group-hover/bar:opacity-70 dark:bg-white" x-on:pointerdown="beginDrag($event, item.row, 'start')" aria-hidden="true"></span>
                                            <span class="absolute top-1.5 -right-1 z-10 h-5 w-2 cursor-ew-resize rounded-sm bg-secondary-dark opacity-0 group-hover/bar:opacity-70 dark:bg-white" x-on:pointerdown="beginDrag($event, item.row, 'end')" aria-hidden="true"></span>
                                        </div>
                                    </template>
                                    <template x-if="canDrag(item.row) && item.row.progressEditable">
                                        <span class="gantt-no-print absolute top-6 z-10 -translate-x-1/2 cursor-col-resize border-x-[5px] border-b-[6px] border-x-transparent border-b-secondary-dark opacity-0 group-hover/bar:opacity-100 dark:border-b-white" x-bind:style="`left:${item.row.progress}%`" x-on:pointerdown="beginDrag($event, item.row, 'progress')" aria-hidden="true"></span>
                                    </template>
                                    <div class="absolute inset-x-0 top-2 h-4 overflow-hidden rounded-[5px]" x-bind:class="[@js($toneBar)[item.row.tone], isCriticalShown(item.row) ? 'ring-2 ring-status-critical ring-offset-1 ring-offset-white dark:ring-offset-secondary-dark' : '']">
                                        <div class="absolute inset-0 bg-white/55 dark:bg-secondary-dark/55"></div>
                                        <div class="absolute inset-y-0 left-0" x-bind:class="@js($toneBar)[item.row.tone]" x-bind:style="`width:${item.row.progress}%`"></div>
                                    </div>
                                    <span class="pointer-events-none absolute top-2 left-full ms-2.5 whitespace-nowrap text-[11px] leading-4 text-secondary-medium dark:text-secondary-soft" x-text="item.row.owner ?? ''"></span>
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
                            <div class="absolute bottom-1 h-1 rounded-full bg-secondary-dark/70 dark:bg-white/70" x-bind:style="actualStyle(item.row)" aria-hidden="true"></div>
                        </div>
                    </div>
                </template>

                {{-- Dependency arrows --}}
                <svg
                    x-ref="linkLayer"
                    class="pointer-events-none absolute top-0 z-[15] overflow-visible"
                    x-bind:style="`left:${gridWidth}px;width:${chartWidth}px;height:${Math.max(bodyHeight, 1)}px`"
                    aria-hidden="true"
                >
                    <defs>
                        <marker id="gantt-arrow-{{ $scope }}" viewBox="0 0 8 8" refX="7" refY="4" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
                            <path d="M0,0 L8,4 L0,8 z" class="fill-secondary-dark dark:fill-white"></path>
                        </marker>
                        <marker id="gantt-arrow-critical-{{ $scope }}" viewBox="0 0 8 8" refX="7" refY="4" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
                            <path d="M0,0 L8,4 L0,8 z" class="fill-status-critical"></path>
                        </marker>
                    </defs>
                    {{-- Alpine's <template> cannot live inside SVG, so the arrows are drawn as markup by linksMarkup(). --}}
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
                    <p class="sticky left-0 p-6 text-sm italic text-secondary-medium dark:text-secondary-soft">{{ __('gantt.nothing_matches') }}</p>
                </template>
            </div>
        </div>
    </div>

    {{-- Legend --}}
    <div class="flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-board-line px-3.5 py-2.5 type-meta text-secondary-medium dark:text-secondary-soft" aria-hidden="true">
        <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-6 rounded-[3px] bg-secondary-medium/45"></span>{{ __('gantt.legend.planned') }}</span>
        <span class="inline-flex items-center gap-1.5"><span class="h-1 w-6 rounded-full bg-secondary-dark/70 dark:bg-white/70"></span>{{ __('gantt.legend.actual') }}</span>
        <span class="inline-flex items-center gap-1.5" x-show="hasBaseline"><span class="gantt-baseline h-1.5 w-6 rounded-sm"></span>{{ __('gantt.legend.baseline') }}</span>
        <span class="inline-flex items-center gap-1.5"><span class="h-1.5 w-6 rounded-sm bg-secondary-dark/85 dark:bg-white/80"></span>{{ __('gantt.legend.summary') }}</span>
        <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rotate-45 rounded-[2px] bg-secondary-medium"></span>{{ __('gantt.legend.milestone') }}</span>
        <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-4 rounded-[3px] bg-funding"></span>{{ __('gantt.legend.funding') }}</span>
        <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-4 rounded-[3px] ring-2 ring-status-critical"></span>{{ __('gantt.legend.critical') }}</span>
        <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-4 rounded-[3px] bg-status-critical"></span>{{ __('gantt.legend.late') }}</span>
        @if ($editable)
            <span class="gantt-no-print ms-auto">{{ __('gantt.hint') }}</span>
        @else
            <span class="gantt-no-print ms-auto">{{ __('gantt.hint_readonly') }}</span>
        @endif
    </div>

    {{-- Link editor --}}
    @if ($editable)
        <template x-teleport="body">
            <div
                x-show="linkMenu !== null"
                x-cloak
                x-on:click.stop
                x-on:keydown.escape.window="linkMenu = null"
                class="fixed z-[70] w-64 rounded-lg border border-secondary-soft bg-white p-3 text-xs shadow-xl dark:border-secondary-medium dark:bg-secondary-dark"
                x-bind:style="linkMenuStyle()"
                role="dialog"
                aria-label="{{ __('gantt.edit_link') }}"
            >
                <template x-if="linkMenu">
                    <div class="flex flex-col gap-2.5">
                        <p class="font-semibold text-secondary-dark dark:text-white">{{ __('gantt.edit_link') }}</p>
                        <p class="text-secondary-medium dark:text-secondary-soft" dir="auto" x-text="rowName(linkMenu.link.from) + ' → ' + rowName(linkMenu.link.to)"></p>
                        <label class="flex flex-col gap-1 text-secondary-medium dark:text-secondary-soft">
                            {{ __('gantt.link_type') }}
                            <select x-model="linkMenu.type" class="{{ $control }}">
                                @foreach ($linkTypes as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="flex flex-col gap-1 text-secondary-medium dark:text-secondary-soft">
                            {{ __('gantt.lag') }}
                            <input type="number" min="-365" max="365" step="1" x-model="linkMenu.lag" class="{{ $control }}" x-on:keydown.enter.prevent="saveLinkMenu()">
                        </label>
                        <div class="flex items-center justify-between gap-2 pt-1">
                            <button type="button" x-on:click="deleteLinkMenu()" class="font-semibold text-status-critical hover:underline">{{ __('gantt.delete_link') }}</button>
                            <div class="flex items-center gap-2">
                                <button type="button" x-on:click="linkMenu = null" class="font-semibold text-secondary-medium hover:text-secondary-dark dark:text-secondary-soft dark:hover:text-white">{{ __('gantt.cancel') }}</button>
                                <button type="button" x-on:click="saveLinkMenu()" class="rounded-md bg-secondary-dark px-2.5 py-1 font-semibold text-white hover:bg-primary dark:bg-white dark:text-secondary-dark">{{ __('gantt.save') }}</button>
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
            class="pointer-events-none fixed z-[60] w-72 rounded-lg border border-secondary-soft bg-white p-3 text-xs shadow-lg dark:border-secondary-medium dark:bg-secondary-dark"
            x-bind:style="tipStyle()"
        >
            <template x-if="hover">
                <div class="flex flex-col gap-1.5">
                    <p class="font-semibold text-secondary-dark dark:text-white" dir="auto" x-text="hover.row.name"></p>
                    <p class="flex items-center gap-2 text-secondary-medium dark:text-secondary-soft">
                        <span x-show="hover.row.statusLabel" x-text="hover.row.statusLabel"></span>
                        <span x-show="hover.row.critical" class="font-semibold text-status-critical">{{ __('gantt.critical') }}</span>
                    </p>
                    <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 tabular-nums">
                        <dt class="text-secondary-medium dark:text-secondary-soft">{{ __('gantt.planned') }}</dt>
                        <dd class="text-secondary-dark dark:text-white" x-text="formatDay(hover.row.startDay) + ' → ' + formatDay(hover.row.endDay)"></dd>
                        <dt class="text-secondary-medium dark:text-secondary-soft">{{ __('gantt.actual') }}</dt>
                        <dd class="text-secondary-dark dark:text-white" x-text="formatDay(hover.row.actualStartDay) + ' → ' + formatDay(hover.row.actualEndDay)"></dd>
                        <template x-if="hasBaseline && hover.row.baselineStartDay !== null">
                            <dt class="text-secondary-medium dark:text-secondary-soft">{{ __('gantt.baseline') }}</dt>
                        </template>
                        <template x-if="hasBaseline && hover.row.baselineStartDay !== null">
                            <dd class="text-secondary-dark dark:text-white" x-text="formatDay(hover.row.baselineStartDay) + ' → ' + formatDay(hover.row.baselineEndDay)"></dd>
                        </template>
                        <dt class="text-secondary-medium dark:text-secondary-soft">{{ __('gantt.working_days') }}</dt>
                        <dd class="text-secondary-dark dark:text-white" x-text="duration(hover.row)"></dd>
                        <dt class="text-secondary-medium dark:text-secondary-soft">{{ __('gantt.progress') }}</dt>
                        <dd class="text-secondary-dark dark:text-white" x-text="hover.row.progress + '%'"></dd>
                        <template x-if="hover.row.float !== null && hover.row.float !== undefined">
                            <dt class="text-secondary-medium dark:text-secondary-soft">{{ __('gantt.total_float') }}</dt>
                        </template>
                        <template x-if="hover.row.float !== null && hover.row.float !== undefined">
                            <dd class="text-secondary-dark dark:text-white" x-text="hover.row.float + ' ' + @js(__('gantt.days'))"></dd>
                        </template>
                        <template x-if="hover.row.predecessors">
                            <dt class="text-secondary-medium dark:text-secondary-soft">{{ __('gantt.predecessors') }}</dt>
                        </template>
                        <template x-if="hover.row.predecessors">
                            <dd class="text-secondary-dark dark:text-white" x-text="hover.row.predecessors"></dd>
                        </template>
                        <template x-if="slip(hover.row) > 0">
                            <dt class="text-status-critical">{{ __('gantt.slip') }}</dt>
                        </template>
                        <template x-if="slip(hover.row) > 0">
                            <dd class="font-semibold text-status-critical" x-text="slip(hover.row) + ' ' + @js(__('gantt.days'))"></dd>
                        </template>
                        <template x-if="hover.row.owner">
                            <dt class="text-secondary-medium dark:text-secondary-soft">{{ __('gantt.owner') }}</dt>
                        </template>
                        <template x-if="hover.row.owner">
                            <dd class="text-secondary-dark dark:text-white" dir="auto" x-text="hover.row.owner"></dd>
                        </template>
                    </dl>
                </div>
            </template>
        </div>
    </template>
</div>
