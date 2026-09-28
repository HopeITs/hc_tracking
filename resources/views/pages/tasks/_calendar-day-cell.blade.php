@php
    use App\Enums\TaskPriority;
    use Illuminate\Support\Js;

    /**
     * @var \Carbon\CarbonImmutable $day
     * @var array{month: \Carbon\CarbonImmutable, weeks: ?array<int, array<int, \Carbon\CarbonImmutable>>, days: ?array<int, \Carbon\CarbonImmutable>, tasksByDay: \Illuminate\Support\Collection<string, \Illuminate\Database\Eloquent\Collection<int, \App\Models\Task>>, unscheduled: \Illuminate\Database\Eloquent\Collection<int, \App\Models\Task>} $calendar
     * @var \Closure $markerTone
     * @var \Closure $markerDot
     * @var \Closure $markerLabel
     * @var \Closure $priorityIconClasses
     */
    $dayTasks = $calendar['tasksByDay']->get($day->toDateString(), new \Illuminate\Database\Eloquent\Collection);
    $dayRowKey = 'calendar|'.$day->toDateString();
    $dayTaskDraft = $this->taskDraft($dayRowKey);
    $dayDraftPriority = TaskPriority::from($dayTaskDraft['priority']);
    $dayDraftAssignees = collect($this->userOptions)->whereIn('id', collect($dayTaskDraft['assignees'])->map(fn ($id) => (int) $id));
@endphp

<div class="flex min-h-4 flex-1 flex-col gap-1" x-data="taskDropList('task-calendar', (wire, id) => wire.rescheduleTask(id, '{{ $day->toDateString() }}'))">
    @foreach ($dayTasks as $task)
        @php $chipLabel = $markerLabel($task); @endphp
        <div
            data-task-chip
            data-task-id="{{ $task->id }}"
            wire:key="task-calendar-chip-{{ $day->toDateString() }}-{{ $task->id }}"
            title="{{ $chipLabel }}"
            class="group flex cursor-grab items-center gap-1.5 rounded-md px-1.5 py-1 text-[10px] transition-transform hover:-translate-y-px {{ $markerTone($task) }}"
        >
            <span class="size-1.5 shrink-0 rounded-full {{ $markerDot($task) }}"></span>
            <a href="{{ route('tasks.show', $task) }}" wire:navigate class="min-w-0 flex-1 truncate text-start font-semibold hover:underline" data-test="task-calendar-chip-{{ $task->id }}">
                {{ $task->title }}
            </a>
            @if ($chipLabel !== null)
                <span class="shrink-0 text-[9px] font-bold uppercase opacity-80" data-test="task-calendar-chip-badge-{{ $task->id }}">{{ $chipLabel }}</span>
            @endif
        </div>
    @endforeach
</div>

@can('create', App\Models\Task::class)
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
@endcan
