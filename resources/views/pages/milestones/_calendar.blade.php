@php
    use App\Enums\MilestoneStatus;

    /** @var array{month: \Carbon\CarbonImmutable, weeks: ?array<int, array<int, \Carbon\CarbonImmutable>>, days: ?array<int, \Carbon\CarbonImmutable>, milestonesByDay: \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, \App\Models\Milestone>>} $calendar */
    $calendar = $this->calendarGrid;
    $today = \Carbon\CarbonImmutable::today();

    $markerTone = function (\App\Models\Milestone $milestone): string {
        if ($milestone->isMissed() || $milestone->isOverdue()) {
            return 'bg-status-critical-soft text-primary';
        }

        return match ($milestone->status) {
            MilestoneStatus::Reached => 'bg-status-good-soft text-status-good',
            MilestoneStatus::Ongoing => 'bg-status-warn-soft text-status-warn',
            MilestoneStatus::Postponed, MilestoneStatus::Cancelled => 'bg-secondary-soft text-secondary-medium dark:bg-secondary-medium dark:text-secondary-soft',
            MilestoneStatus::Planned => 'bg-white text-secondary-dark ring-1 ring-secondary-soft dark:bg-secondary-dark dark:text-white dark:ring-secondary-medium',
        };
    };

    $periodHeading = match ($viewMode) {
        'month' => $calendar['month']->translatedFormat(__('formats.month_year')),
        'day' => $calendar['days'][0]->translatedFormat('l, F j'),
        default => $calendar['days'][0]->translatedFormat('F j').' - '.end($calendar['days'])->translatedFormat('F j'),
    };
@endphp

<div class="flex flex-wrap items-center justify-between gap-3">
    <div class="flex flex-wrap items-center gap-3">
        <x-ui.button size="sm" wire:click="goToToday" data-test="milestone-calendar-today-button">{{ __('common.today') }}</x-ui.button>

        <flux:dropdown position="bottom-start" class="filter-picker">
            <x-ui.button size="sm" icon:trailing="chevron-down" data-test="milestone-calendar-view-mode-button">{{ __('calendar.'.$viewMode) }}</x-ui.button>
            <flux:menu class="filter-menu">
                <div class="px-2 pb-1 pt-1 text-[11px] font-semibold uppercase tracking-[0.1em] text-secondary-medium dark:text-secondary-soft">{{ __('calendar.time_period') }}</div>
                @foreach ([['day', 'D'], ['four_days', '4'], ['week', 'W'], ['month', 'M']] as [$periodKey, $shortcut])
                    <flux:menu.item wire:click="setViewMode('{{ $periodKey }}')" data-test="milestone-calendar-view-mode-{{ $periodKey }}">
                        <span class="min-w-0 flex-1 truncate">{{ __('calendar.'.$periodKey) }}</span>
                        <kbd class="me-1 rounded border border-secondary-soft px-1.5 py-0.5 text-[10px] font-semibold dark:border-secondary-medium">{{ $shortcut }}</kbd>
                        @if ($viewMode === $periodKey)<flux:icon.check class="size-4 shrink-0 text-primary" />@endif
                    </flux:menu.item>
                @endforeach
            </flux:menu>
        </flux:dropdown>

        <div class="flex items-center gap-1">
            <x-ui.button size="sm" wire:click="{{ $viewMode === 'month' ? 'previousMonth' : 'previousPeriod' }}" :aria-label="__('common.previous')">
                <flux:icon.chevron-left variant="micro" class="size-4 rtl:-scale-x-100" />
            </x-ui.button>
            <x-ui.button size="sm" wire:click="{{ $viewMode === 'month' ? 'nextMonth' : 'nextPeriod' }}" :aria-label="__('common.next')">
                <flux:icon.chevron-right variant="micro" class="size-4 rtl:-scale-x-100" />
            </x-ui.button>
        </div>
        <flux:heading size="lg">{{ $periodHeading }}</flux:heading>
    </div>
</div>

@if ($viewMode === 'month')
    <div class="overflow-hidden rounded-xl border border-secondary-soft dark:border-secondary-medium">
        <div class="grid grid-cols-7 border-b border-secondary-soft bg-secondary-soft/40 text-center text-xs font-semibold uppercase tracking-wide text-secondary-medium dark:border-secondary-medium dark:bg-secondary-medium/40 dark:text-secondary-soft">
            @foreach ($calendar['weeks'][0] as $weekdayHeader)
                <div class="py-2">{{ $weekdayHeader->translatedFormat('D') }}</div>
            @endforeach
        </div>

        <div class="grid grid-cols-7">
            @foreach ($calendar['weeks'] as $week)
                @foreach ($week as $day)
                    @php
                        $dayMilestones = $calendar['milestonesByDay']->get($day->toDateString(), new \Illuminate\Support\Collection);
                        $isCurrentMonth = $day->month === $calendar['month']->month;
                        $isToday = $day->isSameDay($today);
                    @endphp
                    <div
                        @can('create', App\Models\Milestone::class) x-on:click="$event.target.closest('[data-milestone-chip]') || $wire.openCreateModal('{{ $day->toDateString() }}')" @endcan
                        wire:key="milestone-calendar-day-{{ $day->toDateString() }}"
                        class="flex min-h-28 cursor-pointer flex-col gap-1 border-b border-e border-secondary-soft p-1.5 text-start last:border-e-0 [&:nth-child(7n)]:border-e-0 hover:bg-secondary-soft/40 dark:border-secondary-medium dark:hover:bg-secondary-medium/40 {{ $isCurrentMonth ? '' : 'opacity-40' }}"
                        data-test="milestone-calendar-day-{{ $day->toDateString() }}"
                    >
                        <span @class([
                            'inline-flex size-6 items-center justify-center rounded-full text-xs font-semibold tabular-nums',
                            'bg-primary text-white' => $isToday,
                            'text-secondary-dark dark:text-white' => ! $isToday,
                        ])>
                            {{ $day->day }}
                        </span>

                        @foreach ($dayMilestones as $milestone)
                            <div data-milestone-chip wire:key="milestone-calendar-chip-{{ $milestone->id }}" class="group flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] {{ $markerTone($milestone) }}">
                                <flux:icon.flag variant="micro" class="size-3 shrink-0" />
                                <a href="{{ route('milestones.show', $milestone) }}" wire:navigate class="min-w-0 flex-1 truncate text-start font-medium hover:underline" data-test="milestone-calendar-chip-{{ $milestone->id }}">
                                    {{ $milestone->name }}
                                </a>
                                @if ($milestone->children_count === 0 && $milestone->tasks_count === 0)
                                    @can('milestones.delete')
                                    <button type="button" wire:click="confirmDelete({{ $milestone->id }})" class="hidden shrink-0 cursor-pointer group-hover:block" aria-label="{{ __('common.delete_name', ['name' => $milestone->name]) }}">
                                        <flux:icon.x-mark variant="micro" class="size-3" />
                                    </button>
                                    @endcan
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endforeach
            @endforeach
        </div>
    </div>
@else
    @php
        $periodDays = $calendar['days'];
        $now = \Carbon\CarbonImmutable::now();
        $currentHour = $now->hour;
        $currentMinuteFraction = $now->minute / 60;
        $gridMinWidth = 4.5 + count($periodDays) * 6;
        $gridColumns = 'grid-template-columns: 4.5rem repeat('.count($periodDays).', minmax(0, 1fr)); min-width: '.$gridMinWidth.'rem;';
    @endphp

    <div class="overflow-hidden rounded-xl border border-secondary-soft dark:border-secondary-medium">
        <div class="overflow-x-auto">
            <div class="grid" style="{{ $gridColumns }}">
                <div class="border-b border-e border-secondary-soft dark:border-secondary-medium"></div>
                @foreach ($periodDays as $day)
                    @php $isToday = $day->isSameDay($today); @endphp
                    <div class="border-b border-e border-secondary-soft p-2 text-center last:border-e-0 dark:border-secondary-medium">
                        <div @class(['text-sm font-semibold', 'text-primary' => $isToday, 'text-secondary-dark dark:text-white' => ! $isToday])>{{ $day->translatedFormat('l') }}</div>
                        <div @class(['text-xs', 'text-primary' => $isToday, 'text-secondary-medium dark:text-secondary-soft' => ! $isToday])>{{ $day->translatedFormat('j M') }}</div>
                    </div>
                @endforeach

                <div class="flex items-center justify-end border-e border-secondary-soft px-2 py-1.5 text-[11px] font-semibold text-secondary-medium dark:border-secondary-medium dark:text-secondary-soft">{{ __('calendar.all_day') }}</div>
                @foreach ($periodDays as $day)
                    @php $dayMilestones = $calendar['milestonesByDay']->get($day->toDateString(), new \Illuminate\Support\Collection); @endphp
                    <div
                        @can('create', App\Models\Milestone::class) x-on:click="$event.target.closest('[data-milestone-chip]') || $wire.openCreateModal('{{ $day->toDateString() }}')" @endcan
                        wire:key="milestone-calendar-allday-{{ $day->toDateString() }}"
                        class="flex min-h-10 cursor-pointer flex-col gap-1 border-e border-secondary-soft p-1.5 text-start last:border-e-0 hover:bg-secondary-soft/40 dark:border-secondary-medium dark:hover:bg-secondary-medium/40"
                        data-test="milestone-calendar-allday-{{ $day->toDateString() }}"
                    >
                        @foreach ($dayMilestones as $milestone)
                            <div data-milestone-chip wire:key="milestone-calendar-period-chip-{{ $milestone->id }}" class="group flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] {{ $markerTone($milestone) }}">
                                <flux:icon.flag variant="micro" class="size-3 shrink-0" />
                                <a href="{{ route('milestones.show', $milestone) }}" wire:navigate class="min-w-0 flex-1 truncate text-start font-medium hover:underline" data-test="milestone-calendar-period-chip-{{ $milestone->id }}">
                                    {{ $milestone->name }}
                                </a>
                                @if ($milestone->children_count === 0 && $milestone->tasks_count === 0)
                                    @can('milestones.delete')
                                    <button type="button" wire:click="confirmDelete({{ $milestone->id }})" class="hidden shrink-0 cursor-pointer group-hover:block" aria-label="{{ __('common.delete_name', ['name' => $milestone->name]) }}">
                                        <flux:icon.x-mark variant="micro" class="size-3" />
                                    </button>
                                    @endcan
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>

            <div class="max-h-96 overflow-y-auto border-t border-secondary-soft dark:border-secondary-medium" x-init="$el.scrollTop = Math.max(0, {{ $currentHour }} * 48 - 160)">
                <div class="grid" style="{{ $gridColumns }}">
                    @for ($hour = 0; $hour < 24; $hour++)
                        <div class="h-12 border-b border-e border-secondary-soft px-2 py-1 text-end text-[11px] text-secondary-medium dark:border-secondary-medium dark:text-secondary-soft">
                            {{ $hour === 0 ? '' : \Carbon\CarbonImmutable::today()->addHours($hour)->translatedFormat('ga') }}
                        </div>
                        @foreach ($periodDays as $day)
                            <div class="relative h-12 border-b border-e border-secondary-soft last:border-e-0 dark:border-secondary-medium">
                                @if ($day->isSameDay($today) && $hour === $currentHour)
                                    <div class="pointer-events-none absolute inset-x-0 h-px bg-primary" style="top: {{ $currentMinuteFraction * 100 }}%;"></div>
                                @endif
                            </div>
                        @endforeach
                    @endfor
                </div>
            </div>
        </div>
    </div>
@endif
