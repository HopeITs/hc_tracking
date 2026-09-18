@php
    use App\Enums\MilestoneStatus;
    use Carbon\CarbonImmutable;

    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Project> $projects */

    $today = CarbonImmutable::today();

    $markerTone = fn (\App\Models\Milestone $milestone): string => match (true) {
        $milestone->status === MilestoneStatus::Reached => 'border-status-good bg-status-good',
        $milestone->status === MilestoneStatus::Missed => 'border-primary bg-primary',
        $milestone->status === MilestoneStatus::Cancelled => 'border-secondary-light bg-secondary-light',
        $milestone->planned_date->lt($today) => 'border-status-warn bg-status-warn',
        default => 'border-secondary-dark bg-white dark:border-white dark:bg-secondary-dark',
    };
@endphp

<x-ui.panel>
    <div class="flex flex-wrap items-center gap-x-5 gap-y-2 border-b border-secondary-soft px-4 py-3 text-xs text-secondary-medium dark:border-secondary-medium dark:text-secondary-soft" aria-hidden="true">
        <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rotate-45 rounded-[2px] border-2 border-status-good bg-status-good"></span>{{ MilestoneStatus::Reached->label() }}</span>
        <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rotate-45 rounded-[2px] border-2 border-secondary-dark bg-white dark:border-white dark:bg-secondary-dark"></span>{{ MilestoneStatus::Pending->label() }}</span>
        <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rotate-45 rounded-[2px] border-2 border-status-warn bg-status-warn"></span>{{ __('milestones.timeline_overdue') }}</span>
        <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rotate-45 rounded-[2px] border-2 border-primary bg-primary"></span>{{ MilestoneStatus::Missed->label() }}</span>
        <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rotate-45 rounded-[2px] border-2 border-secondary-light bg-secondary-light"></span>{{ MilestoneStatus::Cancelled->label() }}</span>
        <span class="inline-flex items-center gap-1.5"><span class="h-3.5 w-0.5 bg-primary"></span>{{ __('milestones.timeline_today') }}</span>
        <span class="inline-flex items-center gap-1.5">
            <span class="flex items-center">
                <span class="size-2.5 rotate-45 rounded-[2px] border-2 border-secondary-medium bg-white dark:border-secondary-soft dark:bg-secondary-dark"></span>
                <span class="h-0 w-4 border-t-2 border-secondary-medium/60 dark:border-secondary-soft/50"></span>
                <span class="size-1.5 rotate-45 rounded-[1px] bg-secondary-medium dark:bg-secondary-soft"></span>
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
        $milestones = $this->flattenMilestones($projectRow->milestones)->sortBy('planned_date')->values();
        $milestonesById = $milestones->keyBy('id');
        $windowStart = $milestones->min('planned_date');
        $windowEnd = $milestones->max('planned_date');
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

        // Zooming into "weeks" or "days" needs finer ticks. Both are capped so a
        // long-running project never dumps hundreds of labels into the page.
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

        // Walk each milestone's parent chain (within the currently visible set)
        // up to its top-most ancestor, so related milestones share one lane.
        $rootIdOf = function (\App\Models\Milestone $milestone) use ($milestonesById, &$rootIdOf): int {
            if ($milestone->parent_milestone_id !== null && $milestonesById->has($milestone->parent_milestone_id)) {
                return $rootIdOf($milestonesById->get($milestone->parent_milestone_id));
            }

            return $milestone->id;
        };

        $families = $milestones
            ->groupBy(fn (\App\Models\Milestone $milestone): int => $rootIdOf($milestone))
            ->map(fn ($members, $rootId) => [
                'root' => $milestonesById->get($rootId),
                'members' => $members->sortBy('planned_date')->values(),
            ])
            ->sortBy(fn (array $family) => $family['root']->planned_date)
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
                    zoom: 'months',
                    pxPerDay: { months: null, weeks: 10, days: 26 },
                    get contentWidth() {
                        const px = this.pxPerDay[this.zoom];

                        return px ? Math.min(this.span * px, 24000) + 'px' : '100%';
                    },
                    dateAt(event) {
                        const rect = event.currentTarget.getBoundingClientRect();
                        const rtl = document.documentElement.dir === 'rtl';
                        let ratio = rtl ? (rect.right - event.clientX) / rect.width : (event.clientX - rect.left) / rect.width;
                        ratio = Math.min(1, Math.max(0, ratio));
                        const dayIndex = Math.round(ratio * this.span);
                        const d = new Date(this.windowStartUtc + dayIndex * 86400000);
                        return d.getUTCFullYear() + '-' + String(d.getUTCMonth() + 1).padStart(2, '0') + '-' + String(d.getUTCDate()).padStart(2, '0');
                    },
                }"
            >
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <div class="inline-flex gap-1 rounded-lg border border-secondary-soft bg-secondary-soft/30 p-1 dark:border-secondary-medium dark:bg-secondary-medium/30" role="group" :aria-label="'{{ __('milestones.zoom') }}'">
                        @foreach (['months' => __('milestones.zoom_months'), 'weeks' => __('milestones.zoom_weeks'), 'days' => __('milestones.zoom_days')] as $zoomLevel => $zoomLabel)
                            <button
                                type="button"
                                x-on:click="zoom = '{{ $zoomLevel }}'"
                                :aria-pressed="(zoom === '{{ $zoomLevel }}').toString()"
                                :class="zoom === '{{ $zoomLevel }}' ? 'bg-white text-secondary-dark shadow-sm dark:bg-secondary-dark dark:text-white' : 'text-secondary-medium hover:text-secondary-dark dark:text-secondary-soft dark:hover:text-white'"
                                class="cursor-pointer rounded-md px-2.5 py-1 text-xs font-semibold transition-colors"
                                data-test="milestone-timeline-zoom-{{ $zoomLevel }}-{{ $projectRow->id }}"
                            >{{ $zoomLabel }}</button>
                        @endforeach
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

                <div class="-mx-1 overflow-x-auto px-1 pb-2">
                    <div :style="zoom === 'months' ? '' : ('min-width: ' + contentWidth)">
                        <div class="relative hidden h-4 @2xl/directory:block" aria-hidden="true" x-show="zoom === 'months'">
                            @foreach ($ticks as $tick)
                                <span class="absolute top-0 text-xs tabular-nums text-secondary-light dark:text-secondary-medium" style="inset-inline-start: {{ $tick['at'] }}%">{{ $tick['label'] }}</span>
                            @endforeach
                        </div>
                        <div class="relative h-4" aria-hidden="true" x-show="zoom === 'weeks'" x-cloak>
                            @foreach ($weekTicks as $tick)
                                <span class="absolute top-0 whitespace-nowrap text-xs tabular-nums text-secondary-light dark:text-secondary-medium" style="inset-inline-start: {{ $tick['at'] }}%">{{ $tick['label'] }}</span>
                            @endforeach
                        </div>
                        <div class="relative h-4" aria-hidden="true" x-show="zoom === 'days'" x-cloak>
                            @foreach ($dayTicks as $tick)
                                <span class="absolute top-0 whitespace-nowrap text-xs tabular-nums text-secondary-light dark:text-secondary-medium" style="inset-inline-start: {{ $tick['at'] }}%">{{ $tick['label'] }}</span>
                            @endforeach
                        </div>

                        <div
                            class="relative mt-2 flex h-9 cursor-copy items-center justify-center gap-1.5 rounded-full border-2 border-dashed border-secondary-medium/70 bg-secondary-soft/20 text-xs font-semibold text-secondary-medium transition-colors hover:border-primary hover:bg-primary/5 hover:text-primary dark:border-secondary-soft/50 dark:bg-white/[0.03] dark:text-secondary-soft"
                            x-on:click="$wire.openCreateModal(dateAt($event), null, {{ $projectRow->id }})"
                            data-test="milestone-timeline-add-{{ $projectRow->id }}"
                            :title="'{{ __('milestones.add_milestone_to_project', ['name' => $projectRow->name]) }}'"
                        >
                            @if ($todayAt !== null)
                                <span aria-hidden="true" x-ref="todayMarker" class="absolute -top-1 -bottom-1 w-0.5 -translate-x-1/2 bg-primary rtl:translate-x-1/2" style="inset-inline-start: {{ $todayAt }}%"></span>
                            @endif
                            <span aria-hidden="true" class="pointer-events-none flex items-center gap-1.5">
                                <flux:icon.plus variant="micro" class="size-3.5" />
                                {{ __('milestones.add_milestone_here') }}
                            </span>
                        </div>

                        <div class="mt-4 flex flex-col gap-3">
                    @foreach ($families as $family)
                        @php
                            $members = $family['members'];
                            $root = $family['root'];
                            $isGroup = $members->count() > 1;
                            $lo = $members->min(fn ($milestone) => $position($milestone->planned_date));
                            $hi = $members->max(fn ($milestone) => $position($milestone->planned_date));
                        @endphp

                        <div
                            wire:key="milestone-family-{{ $root->id }}"
                            class="{{ $isGroup ? 'rounded-xl border border-secondary-soft bg-secondary-soft/20 p-2.5 dark:border-secondary-medium dark:bg-white/[0.03]' : '' }}"
                        >
                            @if ($isGroup)
                                <div class="mb-2 flex items-center gap-1.5 px-1 text-[11px] font-semibold text-secondary-medium dark:text-secondary-soft">
                                    <flux:icon.link variant="micro" class="size-3.5 shrink-0" aria-hidden="true" />
                                    <span class="min-w-0 truncate">{{ $root->name }}</span>
                                    <span class="shrink-0 font-normal text-secondary-light dark:text-secondary-medium">· {{ trans_choice('common.count_milestone', $members->count(), ['count' => $members->count()]) }}</span>
                                </div>
                            @endif

                            <div
                                class="relative h-10 cursor-copy rounded-full bg-secondary-soft dark:bg-secondary-medium"
                                x-on:click="if ($event.target.closest('[data-milestone-marker]')) { return; } $wire.openCreateModal(dateAt($event), null, {{ $projectRow->id }}, {{ $root->id }})"
                                data-test="milestone-timeline-lane-{{ $root->id }}"
                                :title="'{{ __('milestones.add_a_milestone_nested_under_this_one') }}'"
                            >
                                @if ($todayAt !== null)
                                    <span aria-hidden="true" class="absolute -top-1 -bottom-1 w-0.5 -translate-x-1/2 bg-primary rtl:translate-x-1/2" style="inset-inline-start: {{ $todayAt }}%"></span>
                                @endif

                                @if ($isGroup)
                                    <span aria-hidden="true" class="absolute top-1/2 h-0.5 -translate-y-1/2 rounded-full bg-secondary-medium/50 dark:bg-secondary-soft/40" style="inset-inline-start: {{ $lo }}%; width: {{ max($hi - $lo, 0.6) }}%"></span>
                                @endif

                                @foreach ($members as $milestone)
                                    @php $isFamilyRoot = $isGroup && $milestone->id === $root->id; @endphp
                                    <a
                                        href="{{ route('milestones.show', $milestone) }}"
                                        wire:navigate
                                        wire:key="milestone-marker-{{ $milestone->id }}"
                                        data-milestone-marker
                                        class="absolute top-1/2 z-10 flex size-7 -translate-x-1/2 -translate-y-1/2 cursor-pointer items-center justify-center rounded-full focus-visible:outline-2 focus-visible:outline-primary rtl:translate-x-1/2"
                                        style="inset-inline-start: {{ $position($milestone->planned_date) }}%"
                                        aria-label="{{ __('common.edit_name', ['name' => $milestone->name]) }}"
                                    >
                                        <span aria-hidden="true" class="block {{ $isFamilyRoot ? 'size-4' : 'size-3.5' }} rotate-45 rounded-[2px] border-2 shadow-sm ring-2 ring-white dark:ring-secondary-dark {{ $markerTone($milestone) }}"></span>
                                    </a>
                                @endforeach
                            </div>

                            <div class="relative mt-3 {{ $isGroup ? 'h-44' : 'h-36' }}">
                                @foreach ($members as $milestone)
                                    @php
                                        $isOverdue = $milestone->planned_date->lt($today) && $milestone->status === MilestoneStatus::Pending;
                                        $canDeleteMarker = $milestone->children_count === 0 && $milestone->tasks_count === 0;
                                        $isNested = $milestone->parent_milestone_id !== null;
                                    @endphp
                                    <div
                                        class="absolute flex w-40 -translate-x-1/2 flex-col items-center gap-1 focus-within:z-20 hover:z-20 rtl:translate-x-1/2 {{ $isNested ? 'top-6' : 'top-0' }}"
                                        style="inset-inline-start: clamp(5rem, {{ $position($milestone->planned_date) }}%, calc(100% - 5rem))"
                                        wire:key="milestone-card-{{ $milestone->id }}"
                                    >
                                        <span aria-hidden="true" class="h-3 w-px bg-secondary-light dark:bg-secondary-medium"></span>

                                        <div class="w-full rounded-lg border border-secondary-soft bg-white p-2.5 text-xs shadow-sm dark:border-secondary-medium dark:bg-secondary-dark">
                                            <div class="flex items-start justify-between gap-1">
                                                <a href="{{ route('milestones.show', $milestone) }}" wire:navigate class="min-w-0 cursor-pointer text-start font-semibold text-secondary-dark hover:underline dark:text-white">
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
                                                @if ($isOverdue)
                                                    <span class="text-[10px] font-semibold text-status-warn">{{ __('milestones.timeline_overdue') }}</span>
                                                @endif
                                            </div>

                                            <p class="mt-1 tabular-nums text-secondary-medium dark:text-secondary-soft">{{ $milestone->planned_date->translatedFormat(__('formats.date')) }}</p>

                                            <x-ui.employee-chip :employee="$milestone->responsibleUser" compact class="mt-1" />
                                        </div>
                                    </div>
                                @endforeach
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
