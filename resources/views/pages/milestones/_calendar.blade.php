@php
    use App\Enums\MilestoneStatus;

    /** @var array{month: \Carbon\CarbonImmutable, weeks: array<int, array<int, \Carbon\CarbonImmutable>>, milestonesByDay: \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, \App\Models\Milestone>>} $calendar */
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
@endphp

<div class="flex flex-wrap items-center justify-between gap-3">
    <div class="flex flex-wrap items-center gap-3">
        <x-ui.button size="sm" wire:click="goToToday" data-test="milestone-calendar-today-button">{{ __('common.today') }}</x-ui.button>

        <div class="flex items-center gap-1">
            <x-ui.button size="sm" wire:click="previousMonth" :aria-label="__('common.previous')">
                <flux:icon.chevron-left variant="micro" class="size-4 rtl:-scale-x-100" />
            </x-ui.button>
            <x-ui.button size="sm" wire:click="nextMonth" :aria-label="__('common.next')">
                <flux:icon.chevron-right variant="micro" class="size-4 rtl:-scale-x-100" />
            </x-ui.button>
        </div>
        <flux:heading size="lg">{{ $calendar['month']->translatedFormat(__('formats.month_year')) }}</flux:heading>
    </div>
</div>

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
                    x-on:click="$event.target.closest('[data-milestone-chip]') || $wire.openCreateModal('{{ $day->toDateString() }}')"
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
                                <button type="button" wire:click="confirmDelete({{ $milestone->id }})" class="hidden shrink-0 cursor-pointer group-hover:block" aria-label="{{ __('common.delete_name', ['name' => $milestone->name]) }}">
                                    <flux:icon.x-mark variant="micro" class="size-3" />
                                </button>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endforeach
        @endforeach
    </div>
</div>
