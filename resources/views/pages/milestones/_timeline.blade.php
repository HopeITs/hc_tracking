@php
    use App\Enums\MilestoneStatus;
    use Carbon\CarbonImmutable;

    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Project> $projects */

    $today = CarbonImmutable::today();

    $markerTone = fn (\App\Models\Milestone $milestone): string => match (true) {
        $milestone->status === MilestoneStatus::Reached => 'bg-status-good text-white',
        $milestone->status === MilestoneStatus::Cancelled => 'bg-secondary-light text-white',
        $milestone->isMissed() || $milestone->isOverdue() => 'bg-primary text-white',
        $milestone->status === MilestoneStatus::Ongoing => 'bg-status-warn text-white',
        default => 'bg-white text-secondary-dark ring-2 ring-secondary-dark dark:bg-secondary-dark dark:text-white dark:ring-white',
    };

    // Marker rows: depth 0 sits on the main rail, each nested level drops one
    // row below it so a whole family reads as one small tree, not a jumble.
    $topPad = 26;
    $rowStep = 46;
@endphp

<x-ui.panel>
    <div class="flex flex-wrap items-center gap-x-5 gap-y-2 border-b border-secondary-soft px-4 py-3 text-xs text-secondary-medium dark:border-secondary-medium dark:text-secondary-soft" aria-hidden="true">
        <span class="inline-flex items-center gap-1.5"><span class="flex size-4 items-center justify-center rounded-full bg-status-good"><flux:icon.flag variant="micro" class="size-2.5 text-white" /></span>{{ MilestoneStatus::Reached->label() }}</span>
        <span class="inline-flex items-center gap-1.5"><span class="flex size-4 items-center justify-center rounded-full bg-white ring-2 ring-secondary-dark dark:bg-secondary-dark dark:ring-white"><flux:icon.flag variant="micro" class="size-2.5 text-secondary-dark dark:text-white" /></span>{{ MilestoneStatus::Planned->label() }}</span>
        <span class="inline-flex items-center gap-1.5"><span class="flex size-4 items-center justify-center rounded-full bg-status-warn"><flux:icon.flag variant="micro" class="size-2.5 text-white" /></span>{{ MilestoneStatus::Ongoing->label() }}</span>
        <span class="inline-flex items-center gap-1.5"><span class="flex size-4 items-center justify-center rounded-full bg-primary"><flux:icon.flag variant="micro" class="size-2.5 text-white" /></span>{{ __('milestones.missed') }} / {{ __('milestones.overdue') }}</span>
        <span class="inline-flex items-center gap-1.5"><span class="flex size-4 items-center justify-center rounded-full bg-secondary-light"><flux:icon.flag variant="micro" class="size-2.5 text-white" /></span>{{ MilestoneStatus::Cancelled->label() }}</span>
        <span class="inline-flex items-center gap-1.5"><span class="h-3.5 w-0.5 bg-primary"></span>{{ __('milestones.timeline_today') }}</span>
        <span class="inline-flex items-center gap-1.5">
            <span class="relative flex h-3.5 w-5 items-end">
                <span class="absolute inset-x-0 top-0 h-2 w-px bg-secondary-medium dark:bg-secondary-soft"></span>
                <span class="h-px w-full bg-secondary-medium dark:bg-secondary-soft"></span>
            </span>
            {{ __('milestones.related_milestones') }}
        </span>
    </div>
    <div class="flex items-center gap-1.5 px-4 py-2 text-xs text-secondary-light dark:text-secondary-medium" aria-hidden="true">
        <flux:icon.cursor-arrow-ripple variant="micro" class="size-3.5 shrink-0" />
        {{ __('milestones.timeline_click_hint') }}
    </div>
</x-ui.panel>

@foreach ($projects as $projectRow)
    @php
        $milestones = $this->flattenMilestones($projectRow->milestones)->sortBy('planned_start_date')->values();
        $milestonesById = $milestones->keyBy('id');
        $windowStart = $milestones->min('planned_start_date');
        $windowEnd = $milestones->max('planned_start_date');
        $hasWindow = $windowStart !== null && $windowEnd !== null;
        $span = $hasWindow ? max(1, $windowStart->diffInDays($windowEnd)) : 1;
        if ($hasWindow && $windowStart->eq($windowEnd)) {
            $windowStart = $windowStart->subDays(7);
            $windowEnd = $windowEnd->addDays(7);
            $span = $windowStart->diffInDays($windowEnd);
        }

        $position = fn ($date) => $hasWindow ? max(0, min(100, $windowStart->diffInDays($date, false) / $span * 100)) : 0;
        $todayAt = $hasWindow && $today->between($windowStart, $windowEnd) ? $position($today) : null;

        $ticks = collect();
        if ($hasWindow) {
            $byYear = $windowStart->diffInMonths($windowEnd) > 24;
            $cursor = $byYear ? $windowStart->copy()->startOfYear() : $windowStart->copy()->startOfMonth();
            $guard = 0;
            while ($cursor->lte($windowEnd) && $guard < ($byYear ? 40 : 160)) {
                $ticks->push(['label' => $byYear ? $cursor->format('Y') : $cursor->translatedFormat(__('formats.month_short')), 'at' => $position($cursor)]);
                $cursor = $byYear ? $cursor->addYear() : $cursor->addMonthNoOverflow();
                $guard++;
            }
        }

        // Finer ticks for the "weeks"/"days" zoom tiers, both capped so a long
        // project never dumps hundreds of labels into the page at once.
        $weekTicks = collect();
        if ($hasWindow) {
            $cursor = $windowStart->copy()->startOfWeek(CarbonImmutable::SUNDAY);
            $guard = 0;
            while ($cursor->lte($windowEnd) && $guard < 260) {
                if ($cursor->gte($windowStart)) {
                    $weekTicks->push(['label' => $cursor->translatedFormat(__('formats.date_short')), 'at' => $position($cursor)]);
                }
                $cursor = $cursor->addWeek();
                $guard++;
            }
        }

        $dayTicks = collect();
        if ($hasWindow) {
            $dayInterval = max(1, (int) ceil($span / 150));
            $cursor = $windowStart->copy();
            $lastMonth = null;
            $guard = 0;
            while ($cursor->lte($windowEnd) && $guard < 400) {
                $dayTicks->push([
                    'label' => $cursor->month === $lastMonth ? $cursor->format('j') : $cursor->translatedFormat(__('formats.date_short')),
                    'at' => $position($cursor),
                ]);
                $lastMonth = $cursor->month;
                $cursor = $cursor->addDays($dayInterval);
                $guard++;
            }
        }

        // Each milestone's depth is how many hops it is from its top-most
        // ancestor (within the currently visible set). Depth 0 sits on the
        // main rail; nested milestones drop a row per level, connected back
        // to their parent by an elbow line so the family reads as one shape.
        $depthById = [];
        $computeDepth = function (\App\Models\Milestone $milestone) use ($milestonesById, &$computeDepth, &$depthById): int {
            if (isset($depthById[$milestone->id])) {
                return $depthById[$milestone->id];
            }

            $depth = $milestone->parent_milestone_id !== null && $milestonesById->has($milestone->parent_milestone_id)
                ? 1 + $computeDepth($milestonesById->get($milestone->parent_milestone_id))
                : 0;

            return $depthById[$milestone->id] = $depth;
        };
        foreach ($milestones as $milestone) {
            $computeDepth($milestone);
        }
        $maxDepth = $depthById === [] ? 0 : max($depthById);
        $riverHeight = $topPad * 2 + $maxDepth * $rowStep;

        $connectors = $milestones
            ->filter(fn ($milestone) => $milestone->parent_milestone_id !== null && $milestonesById->has($milestone->parent_milestone_id))
            ->map(function ($milestone) use ($milestonesById, $position, $depthById, $topPad, $rowStep) {
                $parent = $milestonesById->get($milestone->parent_milestone_id);

                return [
                    'key' => $milestone->id,
                    'parent_x' => $position($parent->planned_start_date),
                    'child_x' => $position($milestone->planned_start_date),
                    'parent_y' => $topPad + $depthById[$parent->id] * $rowStep,
                    'child_y' => $topPad + $depthById[$milestone->id] * $rowStep,
                ];
            })
            ->values();
    @endphp

    <x-ui.panel :title="$projectRow->name" :subtitle="$projectRow->city?->name ?? __('common.unknown_city')" :count="$projectRow->milestones->count()" wire:key="milestone-timeline-project-{{ $projectRow->id }}">
        <x-slot:toolbar>
            <x-ui.button tone="ghost" size="sm" icon="eye" class="size-9!" :href="route('projects.show', $projectRow)" wire:navigate :aria-label="__('common.view_name', ['name' => $projectRow->name])" />
        </x-slot:toolbar>

        @if (! $hasWindow)
            <x-ui.empty-state icon="flag" :heading="__('common.no_milestones_yet')" />
        @else
            <div
                class="px-4 py-5"
                x-data="{
                    windowStartUtc: Date.UTC({{ $windowStart->year }}, {{ $windowStart->month - 1 }}, {{ $windowStart->day }}),
                    span: {{ $span }},
                    zoomLevel: 0,
                    hover: null,
                    get pxPerDay() {
                        return this.zoomLevel === 0 ? null : 2 + this.zoomLevel;
                    },
                    get contentWidth() {
                        return this.pxPerDay ? Math.min(this.span * this.pxPerDay, 30000) + 'px' : '100%';
                    },
                    get tickMode() {
                        if (this.pxPerDay === null) { return 'months'; }
                        return this.pxPerDay < 10 ? 'weeks' : 'days';
                    },
                    zoomBy(delta) {
                        this.zoomLevel = Math.min(48, Math.max(0, this.zoomLevel + delta));
                    },
                    pointerInfo(event) {
                        const rect = event.currentTarget.getBoundingClientRect();
                        const rtl = document.documentElement.dir === 'rtl';
                        let ratio = rtl ? (rect.right - event.clientX) / rect.width : (event.clientX - rect.left) / rect.width;
                        ratio = Math.min(1, Math.max(0, ratio));
                        const dayIndex = Math.round(ratio * this.span);
                        const d = new Date(this.windowStartUtc + dayIndex * 86400000);

                        return {
                            at: this.span === 0 ? 0 : (dayIndex / this.span) * 100,
                            iso: d.getUTCFullYear() + '-' + String(d.getUTCMonth() + 1).padStart(2, '0') + '-' + String(d.getUTCDate()).padStart(2, '0'),
                            label: d.toLocaleDateString(document.documentElement.lang || undefined, { month: 'short', day: 'numeric' }),
                        };
                    },
                }"
            >
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <div class="inline-flex items-center gap-1 rounded-lg border border-secondary-soft bg-secondary-soft/30 px-1 py-1 dark:border-secondary-medium dark:bg-secondary-medium/30">
                            <button type="button" x-on:click="zoomBy(-6)" class="flex size-6 cursor-pointer items-center justify-center rounded text-secondary-medium hover:bg-white hover:text-secondary-dark dark:text-secondary-soft dark:hover:bg-secondary-dark dark:hover:text-white" :aria-label="'{{ __('common.zoom_out') }}'">
                                <flux:icon.minus variant="micro" class="size-3.5" />
                            </button>
                            <input
                                type="range"
                                min="0"
                                max="48"
                                step="1"
                                x-model.number="zoomLevel"
                                class="accent-primary h-1.5 w-24 cursor-pointer sm:w-32"
                                data-test="milestone-timeline-zoom-{{ $projectRow->id }}"
                                :aria-label="'{{ __('milestones.zoom') }}'"
                            />
                            <button type="button" x-on:click="zoomBy(6)" class="flex size-6 cursor-pointer items-center justify-center rounded text-secondary-medium hover:bg-white hover:text-secondary-dark dark:text-secondary-soft dark:hover:bg-secondary-dark dark:hover:text-white" :aria-label="'{{ __('common.zoom_in') }}'">
                                <flux:icon.plus variant="micro" class="size-3.5" />
                            </button>
                        </div>
                        <span class="min-w-14 text-xs font-semibold text-secondary-medium dark:text-secondary-soft" x-text="{ months: '{{ __('milestones.zoom_months') }}', weeks: '{{ __('milestones.zoom_weeks') }}', days: '{{ __('milestones.zoom_days') }}' }[tickMode]"></span>
                    </div>

                    @if ($todayAt !== null)
                        <button
                            type="button"
                            x-on:click="$nextTick(() => $refs.todayMarker?.scrollIntoView({ inline: 'center', block: 'nearest', behavior: 'smooth' }))"
                            class="inline-flex cursor-pointer items-center gap-1 rounded-md px-2 py-1 text-xs font-semibold text-primary hover:bg-primary/10"
                        >
                            <flux:icon.calendar-days variant="micro" class="size-3.5" />
                            {{ __('common.today') }}
                        </button>
                    @endif
                </div>

                <div class="-mx-1 overflow-x-auto px-1 pb-2" x-on:wheel.ctrl.prevent="zoomBy(Math.sign($event.deltaY) * -3)">
                    <div :style="pxPerDay ? ('min-width: ' + contentWidth) : ''">
                        <div class="relative hidden h-4 @2xl/directory:block" aria-hidden="true" x-show="tickMode === 'months'">
                            @foreach ($ticks as $tick)
                                <span class="absolute top-0 text-xs tabular-nums text-secondary-light dark:text-secondary-medium" style="inset-inline-start: {{ $tick['at'] }}%">{{ $tick['label'] }}</span>
                            @endforeach
                        </div>
                        <div class="relative h-4" aria-hidden="true" x-show="tickMode === 'weeks'" x-cloak>
                            @foreach ($weekTicks as $tick)
                                <span class="absolute top-0 whitespace-nowrap text-xs tabular-nums text-secondary-light dark:text-secondary-medium" style="inset-inline-start: {{ $tick['at'] }}%">{{ $tick['label'] }}</span>
                            @endforeach
                        </div>
                        <div class="relative h-4" aria-hidden="true" x-show="tickMode === 'days'" x-cloak>
                            @foreach ($dayTicks as $tick)
                                <span class="absolute top-0 whitespace-nowrap text-xs tabular-nums text-secondary-light dark:text-secondary-medium" style="inset-inline-start: {{ $tick['at'] }}%">{{ $tick['label'] }}</span>
                            @endforeach
                        </div>

                        <div
                            class="relative mt-2 cursor-copy rounded-xl bg-secondary-soft/25 dark:bg-white/[0.03]"
                            style="height: {{ $riverHeight }}px"
                            x-on:mousemove="hover = $event.target.closest('[data-milestone-marker]') ? null : pointerInfo($event)"
                            x-on:mouseleave="hover = null"
                            x-on:click="if ($event.target.closest('[data-milestone-marker]')) { return; } hover = null; $wire.openCreateModal(pointerInfo($event).iso, null, {{ $projectRow->id }})"
                            data-test="milestone-timeline-river-{{ $projectRow->id }}"
                        >
                            {{-- main rail --}}
                            <span aria-hidden="true" class="absolute inset-x-0 h-px bg-secondary-soft dark:bg-secondary-medium" style="top: {{ $topPad }}px"></span>

                            @if ($todayAt !== null)
                                <span aria-hidden="true" x-ref="todayMarker" class="absolute -top-1 -bottom-1 z-10 w-0.5 -translate-x-1/2 bg-primary rtl:translate-x-1/2" style="inset-inline-start: {{ $todayAt }}%"></span>
                            @endif

                            {{-- hover crosshair + ghost "add here" marker --}}
                            <template x-if="hover">
                                <div class="pointer-events-none absolute inset-y-0 z-20 -translate-x-1/2 rtl:translate-x-1/2" :style="'inset-inline-start: ' + hover.at + '%'">
                                    <span class="absolute inset-y-0 left-1/2 w-px -translate-x-1/2 bg-primary/40"></span>
                                    <span class="absolute rounded-full border-2 border-dashed border-primary/70 bg-white/80 dark:bg-secondary-dark/80" style="top: {{ $topPad }}px; width: 1.75rem; height: 1.75rem; transform: translate(-50%, -50%);"></span>
                                    <span class="absolute start-1/2 -translate-x-1/2 -translate-y-full whitespace-nowrap rounded-md bg-secondary-dark px-1.5 py-0.5 text-[10px] font-semibold text-white dark:bg-white dark:text-secondary-dark rtl:translate-x-1/2" style="top: 0" x-text="hover.label"></span>
                                </div>
                            </template>

                            {{-- family connectors --}}
                            @foreach ($connectors as $connector)
                                <div wire:key="milestone-connector-{{ $connector['key'] }}" aria-hidden="true">
                                    <span class="absolute w-0.5 -translate-x-1/2 bg-secondary-medium/50 dark:bg-secondary-soft/40 rtl:translate-x-1/2" style="inset-inline-start: {{ $connector['parent_x'] }}%; top: {{ $connector['parent_y'] }}px; height: {{ $connector['child_y'] - $connector['parent_y'] }}px"></span>
                                    <span class="absolute h-0.5 bg-secondary-medium/50 dark:bg-secondary-soft/40" style="inset-inline-start: {{ min($connector['parent_x'], $connector['child_x']) }}%; width: {{ max(abs($connector['child_x'] - $connector['parent_x']), 0.4) }}%; top: {{ $connector['child_y'] }}px"></span>
                                </div>
                            @endforeach

                            {{-- markers --}}
                            @foreach ($milestones as $milestone)
                                @php
                                    $canDeleteMarker = $milestone->children_count === 0 && $milestone->tasks_count === 0;
                                    $depth = $depthById[$milestone->id];
                                @endphp
                                <div
                                    wire:key="milestone-node-{{ $milestone->id }}"
                                    class="group/marker absolute z-10 -translate-x-1/2 rtl:translate-x-1/2"
                                    style="inset-inline-start: {{ $position($milestone->planned_start_date) }}%; top: {{ $topPad + $depth * $rowStep }}px"
                                    data-milestone-depth="{{ $depth }}"
                                >
                                    <a
                                        href="{{ route('milestones.show', $milestone) }}"
                                        wire:navigate
                                        wire:key="milestone-marker-{{ $milestone->id }}"
                                        data-milestone-marker
                                        class="relative flex -translate-y-1/2 cursor-pointer items-center justify-center rounded-full shadow-sm ring-2 ring-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary dark:ring-secondary-dark {{ $depth === 0 ? 'size-7' : 'size-6' }} {{ $markerTone($milestone) }}"
                                        aria-label="{{ __('common.edit_name', ['name' => $milestone->name]) }}"
                                    >
                                        <flux:icon.flag variant="micro" class="{{ $depth === 0 ? 'size-3.5' : 'size-3' }}" />

                                        @if (in_array($milestone->id, $this->unreadIds, true))
                                            <span class="absolute -top-1 -start-1 size-2.5 rounded-full bg-primary ring-2 ring-white dark:ring-secondary-dark" data-unread-dot="milestone-{{ $milestone->id }}" title="{{ __('notifications.has_unread') }}"><span class="sr-only">{{ __('notifications.has_unread') }}</span></span>
                                        @endif

                                        @if ($milestone->children_count > 0)
                                            <span aria-hidden="true" class="absolute -top-1 -end-1 flex size-4 items-center justify-center rounded-full bg-secondary-dark text-[9px] font-bold text-white ring-2 ring-white dark:bg-white dark:text-secondary-dark dark:ring-secondary-dark">{{ $milestone->children_count }}</span>
                                        @endif
                                    </a>

                                    <div class="pointer-events-none absolute bottom-full start-1/2 z-30 mb-2 w-56 -translate-x-1/2 opacity-0 transition-opacity duration-150 group-hover/marker:pointer-events-auto group-hover/marker:opacity-100 group-focus-within/marker:pointer-events-auto group-focus-within/marker:opacity-100 rtl:translate-x-1/2">
                                        <div class="rounded-lg border border-secondary-soft bg-white p-2.5 text-xs shadow-lg dark:border-secondary-medium dark:bg-secondary-dark">
                                            <div class="flex items-start justify-between gap-1">
                                                <a href="{{ route('milestones.show', $milestone) }}" wire:navigate tabindex="-1" class="min-w-0 cursor-pointer text-start font-semibold text-secondary-dark hover:underline dark:text-white">
                                                    <span class="line-clamp-2">{{ $milestone->name }}</span>
                                                </a>
                                                @if ($canDeleteMarker)
                                                    <button type="button" wire:click="confirmDelete({{ $milestone->id }})" class="shrink-0 cursor-pointer rounded-md p-1 text-secondary-medium hover:bg-secondary-soft dark:text-secondary-soft dark:hover:bg-secondary-medium" aria-label="{{ __('common.delete_name', ['name' => $milestone->name]) }}">
                                                        <flux:icon.trash class="size-3.5" />
                                                    </button>
                                                @else
                                                    <span class="shrink-0 cursor-not-allowed p-1 text-secondary-light" aria-disabled="true" title="{{ __('milestones.only_a_milestone_with_no_nested_milestones_and_no_tasks_can') }}">
                                                        <flux:icon.lock-closed class="size-3.5" />
                                                    </span>
                                                @endif
                                            </div>

                                            <div class="mt-1.5 flex flex-wrap items-center gap-1">
                                                <x-ui.enum-badge :case="$milestone->status" class="px-1.5! py-0.5! text-[10px]!" />
                                                <x-ui.milestone-flags :milestone="$milestone" class="px-1.5! py-0.5! text-[10px]!" />
                                            </div>

                                            <p class="mt-1 tabular-nums text-secondary-medium dark:text-secondary-soft">{{ $milestone->planned_start_date->translatedFormat(__('formats.date')) }}</p>

                                            <x-ui.employee-chip :employee="$milestone->responsibleUser" compact class="mt-1" />

                                            <button
                                                type="button"
                                                wire:click="openCreateModal(null, null, null, {{ $milestone->id }})"
                                                data-test="milestone-timeline-add-related-{{ $milestone->id }}"
                                                class="mt-2 flex w-full cursor-pointer items-center justify-center gap-1 rounded-md border border-dashed border-secondary-soft py-1 text-[11px] font-semibold text-secondary-medium hover:border-primary hover:text-primary dark:border-secondary-medium dark:text-secondary-soft"
                                            >
                                                <flux:icon.plus variant="micro" class="size-3" />
                                                {{ __('milestones.add_related_milestone') }}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </x-ui.panel>
@endforeach
