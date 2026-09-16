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
    </div>
</x-ui.panel>

@foreach ($projects as $projectRow)
    @php
        $milestones = $this->flattenMilestones($projectRow->milestones)->sortBy('planned_date')->values();
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
    @endphp

    <x-ui.panel :title="$projectRow->name" :subtitle="$projectRow->city?->name ?? __('common.unknown_city')" :count="$milestones->count()" wire:key="milestone-timeline-project-{{ $projectRow->id }}">
        <x-slot:toolbar>
            <x-ui.button tone="ghost" size="sm" icon="eye" class="size-9!" :href="route('projects.show', $projectRow)" wire:navigate :aria-label="__('common.view_name', ['name' => $projectRow->name])" />
        </x-slot:toolbar>

        @if (! $hasWindow)
            <x-ui.empty-state icon="flag" :heading="__('common.no_milestones_yet')" />
        @else
            <div class="px-4 py-5">
                <div class="relative hidden h-4 @2xl/directory:block" aria-hidden="true">
                    @foreach ($ticks as $tick)
                        <span class="absolute top-0 text-xs tabular-nums text-secondary-light dark:text-secondary-medium" style="inset-inline-start: {{ $tick['at'] }}%">{{ $tick['label'] }}</span>
                    @endforeach
                </div>

                <div class="relative mt-2 h-10 rounded-full bg-secondary-soft dark:bg-secondary-medium">
                    @if ($todayAt !== null)
                        <span aria-hidden="true" class="absolute -top-1 -bottom-1 w-0.5 -translate-x-1/2 bg-primary rtl:translate-x-1/2" style="inset-inline-start: {{ $todayAt }}%"></span>
                    @endif

                    @foreach ($milestones as $milestone)
                        <button
                            type="button"
                            wire:click="openEditModal({{ $milestone->id }})"
                            wire:key="milestone-marker-{{ $milestone->id }}"
                            class="absolute top-1/2 z-10 flex size-7 -translate-x-1/2 -translate-y-1/2 cursor-pointer items-center justify-center rounded-full focus-visible:outline-2 focus-visible:outline-primary rtl:translate-x-1/2"
                            style="inset-inline-start: {{ $position($milestone->planned_date) }}%"
                            aria-label="{{ __('common.edit_name', ['name' => $milestone->name]) }}"
                        >
                            <span aria-hidden="true" class="block size-3.5 rotate-45 rounded-[2px] border-2 shadow-sm ring-2 ring-white dark:ring-secondary-dark {{ $markerTone($milestone) }}"></span>
                        </button>
                    @endforeach
                </div>

                <div class="relative mt-3 h-36">
                    @foreach ($milestones as $milestone)
                        @php
                            $isOverdue = $milestone->planned_date->lt($today) && $milestone->status === MilestoneStatus::Pending;
                            $canDeleteMarker = $milestone->children_count === 0 && $milestone->tasks_count === 0;
                        @endphp
                        <div
                            class="absolute top-0 flex w-40 -translate-x-1/2 flex-col items-center gap-1 focus-within:z-20 hover:z-20 rtl:translate-x-1/2"
                            style="inset-inline-start: clamp(5rem, {{ $position($milestone->planned_date) }}%, calc(100% - 5rem))"
                            wire:key="milestone-card-{{ $milestone->id }}"
                        >
                            <span aria-hidden="true" class="h-3 w-px bg-secondary-light dark:bg-secondary-medium"></span>

                            <div class="w-full rounded-lg border border-secondary-soft bg-white p-2.5 text-xs shadow-sm dark:border-secondary-medium dark:bg-secondary-dark">
                                <div class="flex items-start justify-between gap-1">
                                    <button type="button" wire:click="openEditModal({{ $milestone->id }})" class="min-w-0 cursor-pointer text-start font-semibold text-secondary-dark hover:underline dark:text-white">
                                        <span class="line-clamp-2">{{ $milestone->name }}</span>
                                    </button>
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
        @endif
    </x-ui.panel>
@endforeach
