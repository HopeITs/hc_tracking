@php
    use App\Enums\TaskPriority;
    use App\Enums\TaskStatus;
    use Illuminate\Support\Js;

    /** @var array{month: \Carbon\CarbonImmutable, weeks: array<int, array<int, \Carbon\CarbonImmutable>>, tasksByDay: \Illuminate\Support\Collection<string, \Illuminate\Database\Eloquent\Collection<int, \App\Models\Task>>, unscheduled: \Illuminate\Database\Eloquent\Collection<int, \App\Models\Task>} $calendar */
    $calendar = $this->calendarGrid;
    $today = \Carbon\CarbonImmutable::today();

    $markerTone = function (\App\Models\Task $task) use ($today): string {
        if ($task->status === TaskStatus::Done) {
            return 'bg-status-good-soft text-status-good';
        }

        if ($task->due_date !== null && $task->due_date->lt($today)) {
            return 'bg-status-critical-soft text-primary dark:text-status-critical';
        }

        return 'bg-white text-secondary-dark ring-1 ring-secondary-soft hover:ring-secondary-light dark:bg-secondary-dark dark:text-white dark:ring-secondary-medium';
    };

    $markerDot = function (\App\Models\Task $task) use ($today): string {
        if ($task->status === TaskStatus::Done) {
            return 'bg-status-good';
        }

        if ($task->due_date !== null && $task->due_date->lt($today)) {
            return 'bg-primary';
        }

        return match ($task->priority) {
            TaskPriority::High => 'bg-primary',
            TaskPriority::Medium => 'bg-status-warn',
            TaskPriority::Low => 'bg-secondary-light',
        };
    };

    $priorityIconClasses = fn (TaskPriority $case): string => match ($case) {
        TaskPriority::High => 'text-primary',
        TaskPriority::Medium => 'text-status-warn',
        TaskPriority::Low => 'text-secondary-light',
    };
@endphp

<div class="grid gap-4 @6xl/directory:grid-cols-[minmax(0,1fr)_16rem]">
    <div class="flex flex-col gap-3">
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-secondary-soft bg-secondary-soft/20 px-3 py-2.5 dark:border-secondary-medium dark:bg-secondary-medium/10">
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
                <flux:heading size="lg" class="font-bold!">{{ $calendar['month']->translatedFormat(__('formats.month_year')) }}</flux:heading>
            </div>
        </div>

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
                            $dayTasks = $calendar['tasksByDay']->get($day->toDateString(), new \Illuminate\Database\Eloquent\Collection);
                            $isCurrentMonth = $day->month === $calendar['month']->month;
                            $isToday = $day->isSameDay($today);
                            $dayRowKey = 'calendar|'.$day->toDateString();
                            $dayTaskDraft = $this->taskDraft($dayRowKey);
                            $dayDraftPriority = TaskPriority::from($dayTaskDraft['priority']);
                            $dayDraftAssignees = collect($this->userOptions)->whereIn('id', collect($dayTaskDraft['assignees'])->map(fn ($id) => (int) $id));
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

                            <div class="flex min-h-4 flex-1 flex-col gap-1" x-data="taskDropList('task-calendar', (wire, id) => wire.rescheduleTask(id, '{{ $day->toDateString() }}'))">
                                @foreach ($dayTasks as $task)
                                    <div data-task-chip data-task-id="{{ $task->id }}" wire:key="task-calendar-chip-{{ $task->id }}" class="group flex cursor-grab items-center gap-1.5 rounded-md px-1.5 py-1 text-[10px] transition-transform hover:-translate-y-px {{ $markerTone($task) }}">
                                        <span class="size-1.5 shrink-0 rounded-full {{ $markerDot($task) }}"></span>
                                        <a href="{{ route('tasks.show', $task) }}" wire:navigate class="min-w-0 flex-1 truncate text-start font-semibold hover:underline" data-test="task-calendar-chip-{{ $task->id }}">
                                            {{ $task->title }}
                                        </a>
                                    </div>
                                @endforeach
                            </div>

                            <button
                                type="button"
                                x-show="!addOpen[{{ Js::from($day->toDateString()) }}]"
                                x-on:click="addOpen[{{ Js::from($day->toDateString()) }}] = true; $nextTick(() => $refs['calendarAddInput{{ $day->toDateString() }}']?.focus())"
                                class="inline-flex shrink-0 items-center gap-0.5 self-start rounded px-1 py-0.5 text-[10px] text-secondary-medium opacity-0 hover:bg-secondary-soft group-hover/day:opacity-100 dark:text-secondary-soft dark:hover:bg-secondary-medium"
                                :aria-label="{{ Js::from(__('tasks.add_task')) }}"
                                data-test="task-calendar-add-toggle-{{ $day->toDateString() }}"
                            >
                                <flux:icon.plus variant="micro" class="size-3 shrink-0" aria-hidden="true" />
                                {{ __('tasks.add_task') }}
                            </button>

                            <div x-show="addOpen[{{ Js::from($day->toDateString()) }}]" x-cloak class="flex flex-col gap-1" data-test="task-calendar-add-row-{{ $day->toDateString() }}">
                                <input
                                    type="text"
                                    x-ref="calendarAddInput{{ $day->toDateString() }}"
                                    placeholder="{{ __('tasks.add_task') }}"
                                    class="w-full min-w-0 border-0 bg-transparent p-0.5 text-[11px] text-secondary-dark placeholder:text-secondary-light focus:ring-0 focus:outline-none dark:text-white"
                                    wire:keydown.enter="quickCreateTask($event.target.value, null, null, {{ Js::from($dayRowKey) }}, {{ Js::from($day->toDateString()) }}); $event.target.value = ''"
                                    data-test="task-calendar-quick-add-{{ $day->toDateString() }}"
                                />
                                <div class="flex items-center gap-1">
                                    <x-ui.multi-select-popover
                                        :label="__('tasks.assignees')"
                                        class="inline-flex shrink-0 cursor-pointer items-center -space-x-1.5 rtl:space-x-reverse"
                                        data-test="task-calendar-add-assignee-trigger-{{ $day->toDateString() }}"
                                    >
                                        <x-slot:trigger>
                                            @if ($dayDraftAssignees->isEmpty())
                                                <span class="inline-flex size-5 items-center justify-center rounded-full border border-dashed border-secondary-light text-secondary-light">
                                                    <flux:icon.user-plus variant="micro" class="size-2.5" />
                                                </span>
                                            @else
                                                @foreach ($dayDraftAssignees->take(2) as $userOption)
                                                    <span class="inline-flex size-5 items-center justify-center rounded-full border-2 border-white bg-secondary-dark text-[8px] font-semibold text-white dark:border-secondary-dark dark:bg-secondary-medium">
                                                        {{ Illuminate\Support\Str::of($userOption['name'])->explode(' ')->map(fn ($part) => mb_substr($part, 0, 1))->take(2)->implode('') }}
                                                    </span>
                                                @endforeach
                                                @if ($dayDraftAssignees->count() > 2)
                                                    <span class="inline-flex size-5 items-center justify-center rounded-full border-2 border-white bg-secondary-soft text-[8px] font-semibold text-secondary-dark dark:border-secondary-dark dark:bg-secondary-medium dark:text-white">
                                                        +{{ $dayDraftAssignees->count() - 2 }}
                                                    </span>
                                                @endif
                                            @endif
                                        </x-slot:trigger>

                                        @foreach ($this->userOptions as $userOption)
                                            @php $isDayDraftAssigned = in_array((string) $userOption['id'], $dayTaskDraft['assignees'], true); @endphp
                                            <button
                                                type="button"
                                                role="option"
                                                aria-selected="{{ $isDayDraftAssigned ? 'true' : 'false' }}"
                                                wire:click="toggleTaskDraftAssignee({{ Js::from($dayRowKey) }}, {{ $userOption['id'] }})"
                                                wire:key="task-calendar-add-{{ $day->toDateString() }}-assignee-{{ $userOption['id'] }}"
                                                x-show="optionSearch === '' || $el.textContent.toLowerCase().includes(optionSearch.toLowerCase())"
                                                class="flex w-full cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-start text-sm hover:bg-secondary-soft dark:hover:bg-secondary-medium"
                                                data-test="task-calendar-add-assignee-option-{{ $day->toDateString() }}-{{ $userOption['id'] }}"
                                            >
                                                <span class="min-w-0 flex-1 truncate">{{ $userOption['name'] }} · {{ $userOption['secondary_name'] }}</span>
                                                @if ($isDayDraftAssigned)
                                                    <flux:icon.check class="size-4 shrink-0 text-primary" />
                                                @endif
                                            </button>
                                        @endforeach
                                    </x-ui.multi-select-popover>

                                    <flux:dropdown position="bottom" align="start">
                                        <button type="button" aria-label="{{ __('tasks.priority') }}: {{ $dayDraftPriority->label() }}" class="inline-flex size-5 shrink-0 cursor-pointer items-center justify-center rounded hover:bg-secondary-soft dark:hover:bg-secondary-medium" data-test="task-calendar-add-priority-trigger-{{ $day->toDateString() }}">
                                            <flux:icon.flag :variant="$dayDraftPriority === TaskPriority::Low ? 'outline' : 'solid'" class="size-3 {{ $priorityIconClasses($dayDraftPriority) }}" />
                                        </button>
                                        <flux:menu>
                                            @foreach (TaskPriority::cases() as $priorityCase)
                                                <flux:menu.item wire:click="$set('taskDrafts.{{ $dayRowKey }}.priority', '{{ $priorityCase->value }}')" :wire:key="'task-calendar-add-'.$day->toDateString().'-priority-'.$priorityCase->value">
                                                    <flux:icon.flag :variant="$priorityCase === TaskPriority::Low ? 'outline' : 'solid'" class="size-4 shrink-0 {{ $priorityIconClasses($priorityCase) }}" />
                                                    <span class="min-w-0 truncate">{{ $priorityCase->label() }}</span>
                                                    @if ($dayDraftPriority === $priorityCase)<flux:icon.check class="ms-auto size-4 shrink-0 text-secondary-medium" />@endif
                                                </flux:menu.item>
                                            @endforeach
                                        </flux:menu>
                                    </flux:dropdown>
                                </div>
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
