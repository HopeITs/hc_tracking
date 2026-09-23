@php
    use App\Enums\TaskPriority;
    use App\Enums\TaskStatus;
    use Illuminate\Support\Js;

    /** @var array{month: \Carbon\CarbonImmutable, weeks: ?array<int, array<int, \Carbon\CarbonImmutable>>, days: ?array<int, \Carbon\CarbonImmutable>, tasksByDay: \Illuminate\Support\Collection<string, \Illuminate\Database\Eloquent\Collection<int, \App\Models\Task>>, unscheduled: \Illuminate\Database\Eloquent\Collection<int, \App\Models\Task>} $calendar */
    $calendar = $this->calendarGrid;
    $today = \Carbon\CarbonImmutable::today();

    $markerTone = function (\App\Models\Task $task): string {
        if ($task->status === TaskStatus::Done) {
            return 'bg-status-good-soft text-status-good';
        }

        if ($task->isOverdue() || $task->isMissed()) {
            return 'bg-status-critical-soft text-primary dark:text-status-critical';
        }

        return 'bg-white text-secondary-dark ring-1 ring-secondary-soft hover:ring-secondary-light dark:bg-secondary-dark dark:text-white dark:ring-secondary-medium';
    };

    $markerDot = function (\App\Models\Task $task): string {
        if ($task->status === TaskStatus::Done) {
            return 'bg-status-good';
        }

        if ($task->isOverdue() || $task->isMissed()) {
            return 'bg-primary';
        }

        return match ($task->priority) {
            TaskPriority::High => 'bg-primary',
            TaskPriority::Medium => 'bg-status-warn',
            TaskPriority::Low => 'bg-secondary-light',
        };
    };

    $markerLabel = function (\App\Models\Task $task): ?string {
        if ($task->isMissed()) {
            return __('tasks.missed');
        }

        if ($task->isOverdue()) {
            return __('tasks.overdue');
        }

        return null;
    };

    $priorityIconClasses = fn (TaskPriority $case): string => match ($case) {
        TaskPriority::High => 'text-primary',
        TaskPriority::Medium => 'text-status-warn',
        TaskPriority::Low => 'text-secondary-light',
    };

    $periodHeading = match ($viewMode) {
        'month' => $calendar['month']->translatedFormat(__('formats.month_year')),
        'day' => $calendar['days'][0]->translatedFormat('l, F j'),
        default => $calendar['days'][0]->translatedFormat('F j').' - '.end($calendar['days'])->translatedFormat('F j'),
    };
@endphp

<div class="grid gap-4 @6xl/directory:grid-cols-[minmax(0,1fr)_16rem]">
    <div class="flex flex-col gap-3">
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-secondary-soft bg-secondary-soft/20 px-3 py-2.5 dark:border-secondary-medium dark:bg-secondary-medium/10">
            <div class="flex flex-wrap items-center gap-3">
                <x-ui.button size="sm" wire:click="goToToday" data-test="task-calendar-today-button">{{ __('common.today') }}</x-ui.button>

                <flux:dropdown position="bottom-start" class="filter-picker">
                    <x-ui.button size="sm" icon:trailing="chevron-down" data-test="task-calendar-view-mode-button">{{ __('calendar.'.$viewMode) }}</x-ui.button>
                    <flux:menu class="filter-menu">
                        <div class="px-2 pb-1 pt-1 text-[11px] font-semibold uppercase tracking-[0.1em] text-secondary-medium dark:text-secondary-soft">{{ __('calendar.time_period') }}</div>
                        @foreach ([['day', 'D'], ['four_days', '4'], ['week', 'W'], ['month', 'M']] as [$periodKey, $shortcut])
                            <flux:menu.item wire:click="setViewMode('{{ $periodKey }}')" data-test="task-calendar-view-mode-{{ $periodKey }}">
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
                <flux:heading size="lg" class="font-bold!">{{ $periodHeading }}</flux:heading>
            </div>
        </div>

        @if ($viewMode === 'month')
            <div class="overflow-hidden rounded-2xl border border-secondary-soft shadow-[0_1px_2px_rgba(16,24,40,0.04)] dark:border-secondary-medium">
                <div class="grid grid-cols-7 border-b border-secondary-soft bg-secondary-soft/40 text-center text-[11px] font-bold tracking-wider text-secondary-medium uppercase dark:border-secondary-medium dark:bg-secondary-medium/40 dark:text-secondary-soft">
                    @foreach ($calendar['weeks'][0] as $weekdayHeader)
                        <div class="py-2.5">{{ $weekdayHeader->translatedFormat('D') }}</div>
                    @endforeach
                </div>

                <div class="grid grid-cols-7" x-data="{ addOpen: {} }">
                    @foreach ($calendar['weeks'] as $week)
                        @foreach ($week as $day)
                            @php
                                $isCurrentMonth = $day->month === $calendar['month']->month;
                                $isToday = $day->isSameDay($today);
                            @endphp
                            <div
                                wire:key="task-calendar-day-{{ $day->toDateString() }}"
                                @class([
                                    'group/day flex min-h-28 flex-col gap-1 border-b border-e border-secondary-soft p-1.5 text-start transition-colors last:border-e-0 [&:nth-child(7n)]:border-e-0 hover:bg-secondary-soft/20 dark:border-secondary-medium dark:hover:bg-secondary-medium/10',
                                    'opacity-40' => ! $isCurrentMonth,
                                    'bg-status-active-soft/40 dark:bg-status-active-soft/10' => $isToday,
                                ])
                                data-test="task-calendar-day-{{ $day->toDateString() }}"
                            >
                                <span @class([
                                    'inline-flex size-6 items-center justify-center rounded-full text-xs font-bold tabular-nums',
                                    'bg-primary text-white' => $isToday,
                                    'text-secondary-dark dark:text-white' => ! $isToday,
                                ])>
                                    {{ $day->day }}
                                </span>

                                @include('pages.tasks._calendar-day-cell', ['day' => $day, 'calendar' => $calendar, 'markerTone' => $markerTone, 'markerDot' => $markerDot, 'markerLabel' => $markerLabel, 'priorityIconClasses' => $priorityIconClasses])
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

            <div class="overflow-hidden rounded-2xl border border-secondary-soft shadow-[0_1px_2px_rgba(16,24,40,0.04)] dark:border-secondary-medium">
                <div class="overflow-x-auto" x-data="{ addOpen: {} }">
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
                            @php $isToday = $day->isSameDay($today); @endphp
                            <div
                                wire:key="task-calendar-allday-{{ $day->toDateString() }}"
                                @class([
                                    'group/day flex min-h-24 flex-col gap-1 border-e border-secondary-soft p-1.5 text-start transition-colors last:border-e-0 hover:bg-secondary-soft/20 dark:border-secondary-medium dark:hover:bg-secondary-medium/10',
                                    'bg-status-active-soft/40 dark:bg-status-active-soft/10' => $isToday,
                                ])
                                data-test="task-calendar-allday-{{ $day->toDateString() }}"
                            >
                                @include('pages.tasks._calendar-day-cell', ['day' => $day, 'calendar' => $calendar, 'markerTone' => $markerTone, 'markerDot' => $markerDot, 'markerLabel' => $markerLabel, 'priorityIconClasses' => $priorityIconClasses])
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
    </div>

    <x-ui.panel :title="__('tasks.unscheduled')" :count="$calendar['unscheduled']->count()" :subtitle="__('tasks.unscheduled_tasks_have_no_due_date_drag_one_onto_a_day_to_sch')">
        <div class="flex min-h-24 flex-col gap-2 p-2" x-data="taskDropList('task-calendar', (wire, id) => wire.rescheduleTask(id, ''))" data-test="task-calendar-unscheduled">
            @forelse ($calendar['unscheduled'] as $task)
                <div
                    class="flex cursor-grab flex-col gap-1.5 rounded-xl border border-secondary-soft bg-white p-2.5 shadow-[0_1px_2px_rgba(16,24,40,0.04)] transition-all duration-150 hover:-translate-y-0.5 hover:shadow-md active:cursor-grabbing dark:border-secondary-medium dark:bg-secondary-dark"
                    data-task-id="{{ $task->id }}"
                    wire:key="task-unscheduled-{{ $task->id }}"
                >
                    <a href="{{ route('tasks.show', $task) }}" wire:navigate class="truncate text-xs font-semibold hover:text-primary hover:underline dark:hover:text-status-critical">{{ $task->title }}</a>
                    <x-ui.enum-badge :case="$task->priority" class="w-fit" />
                </div>
            @empty
                <p class="px-1 py-6 text-center text-xs text-secondary-light">{{ __('tasks.drop_a_task_here_to_unschedule_it') }}</p>
            @endforelse
        </div>
    </x-ui.panel>
</div>
