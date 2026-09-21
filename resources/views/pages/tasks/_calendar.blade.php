@php
    use App\Enums\TaskStatus;

    /** @var array{month: \Carbon\CarbonImmutable, weeks: array<int, array<int, \Carbon\CarbonImmutable>>, tasksByDay: \Illuminate\Support\Collection<string, \Illuminate\Database\Eloquent\Collection<int, \App\Models\Task>>, unscheduled: \Illuminate\Database\Eloquent\Collection<int, \App\Models\Task>} $calendar */
    $calendar = $this->calendarGrid;
    $today = \Carbon\CarbonImmutable::today();

    $markerTone = function (\App\Models\Task $task) use ($today): string {
        if ($task->status === TaskStatus::Done) {
            return 'bg-status-good-soft text-status-good';
        }

        if ($task->due_date !== null && $task->due_date->lt($today)) {
            return 'bg-status-critical-soft text-primary';
        }

        return 'bg-white text-secondary-dark ring-1 ring-secondary-soft dark:bg-secondary-dark dark:text-white dark:ring-secondary-medium';
    };
@endphp

<div class="grid gap-4 @6xl/directory:grid-cols-[minmax(0,1fr)_16rem]">
    <div class="flex flex-col gap-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-3">
                <x-ui.button size="sm" wire:click="goToToday" data-test="task-calendar-today-button">{{ __('common.today') }}</x-ui.button>

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
            <div class="grid grid-cols-7 border-b border-secondary-soft bg-secondary-soft/40 text-center text-xs font-semibold tracking-wide text-secondary-medium uppercase dark:border-secondary-medium dark:bg-secondary-medium/40 dark:text-secondary-soft">
                @foreach ($calendar['weeks'][0] as $weekdayHeader)
                    <div class="py-2">{{ $weekdayHeader->translatedFormat('D') }}</div>
                @endforeach
            </div>

            <div class="grid grid-cols-7">
                @foreach ($calendar['weeks'] as $week)
                    @foreach ($week as $day)
                        @php
                            $dayTasks = $calendar['tasksByDay']->get($day->toDateString(), new \Illuminate\Database\Eloquent\Collection);
                            $isCurrentMonth = $day->month === $calendar['month']->month;
                            $isToday = $day->isSameDay($today);
                        @endphp
                        <div
                            x-on:click="$event.target.closest('[data-task-chip]') || $wire.openCreateDrawer('{{ $day->toDateString() }}')"
                            wire:key="task-calendar-day-{{ $day->toDateString() }}"
                            class="flex min-h-28 cursor-pointer flex-col gap-1 border-b border-e border-secondary-soft p-1.5 text-start last:border-e-0 hover:bg-secondary-soft/40 [&:nth-child(7n)]:border-e-0 dark:border-secondary-medium dark:hover:bg-secondary-medium/40 {{ $isCurrentMonth ? '' : 'opacity-40' }}"
                            data-test="task-calendar-day-{{ $day->toDateString() }}"
                        >
                            <span @class([
                                'inline-flex size-6 items-center justify-center rounded-full text-xs font-semibold tabular-nums',
                                'bg-primary text-white' => $isToday,
                                'text-secondary-dark dark:text-white' => ! $isToday,
                            ])>
                                {{ $day->day }}
                            </span>

                            <div class="flex min-h-4 flex-1 flex-col gap-1" x-data="taskDropList('task-calendar', (wire, id) => wire.rescheduleTask(id, '{{ $day->toDateString() }}'))">
                                @foreach ($dayTasks as $task)
                                    <div data-task-chip data-task-id="{{ $task->id }}" wire:key="task-calendar-chip-{{ $task->id }}" class="group flex cursor-grab items-center gap-1 rounded px-1.5 py-0.5 text-[10px] {{ $markerTone($task) }}">
                                        <flux:icon.clipboard-document-check variant="micro" class="size-3 shrink-0" />
                                        <a href="{{ route('tasks.show', $task) }}" wire:navigate class="min-w-0 flex-1 truncate text-start font-medium hover:underline" data-test="task-calendar-chip-{{ $task->id }}">
                                            {{ $task->title }}
                                        </a>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                @endforeach
            </div>
        </div>
    </div>

    <x-ui.panel :title="__('tasks.unscheduled')" :count="$calendar['unscheduled']->count()" :subtitle="__('tasks.unscheduled_tasks_have_no_due_date_drag_one_onto_a_day_to_sch')">
        <div class="flex min-h-24 flex-col gap-2 p-2" x-data="taskDropList('task-calendar', (wire, id) => wire.rescheduleTask(id, ''))" data-test="task-calendar-unscheduled">
            @forelse ($calendar['unscheduled'] as $task)
                <div
                    class="flex cursor-grab flex-col gap-1 rounded-lg border border-secondary-soft bg-white p-2.5 shadow-[0_1px_2px_rgba(16,24,40,0.04)] dark:border-secondary-medium dark:bg-secondary-dark"
                    data-task-id="{{ $task->id }}"
                    wire:key="task-unscheduled-{{ $task->id }}"
                >
                    <a href="{{ route('tasks.show', $task) }}" wire:navigate class="truncate text-xs font-semibold hover:underline">{{ $task->title }}</a>
                    <x-ui.enum-badge :case="$task->priority" class="w-fit" />
                </div>
            @empty
                <p class="px-1 py-6 text-center text-xs text-secondary-light">{{ __('tasks.drop_a_task_here_to_unschedule_it') }}</p>
            @endforelse
        </div>
    </x-ui.panel>
</div>
