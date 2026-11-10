@php
    use App\Enums\TaskPriority;
    use Illuminate\Support\Js;

    /**
     * One day's tasks on the tasks calendar: the chips (the first $chipLimit
     * of them, the rest behind a "+N more" popover), then the quick-add
     * composer. Included by _calendar.blade.php for month cells and for the
     * day / 4-days / week columns.
     *
     * @var \Carbon\CarbonImmutable $day
     * @var array{tasksByDay: \Illuminate\Support\Collection<string, \Illuminate\Database\Eloquent\Collection<int, \App\Models\Task>>} $calendar
     * @var ?int $chipLimit null shows every chip
     * @var bool $alignEnd open the "+N more" popover from the cell's end edge
     * @var \Closure $chipState
     * @var \Closure $markerLabel
     * @var \Closure $priorityIconClasses
     */
    $dayTasks = $calendar['tasksByDay']->get($day->toDateString(), new \Illuminate\Database\Eloquent\Collection);
    $visibleTasks = $chipLimit === null || $dayTasks->count() <= $chipLimit ? $dayTasks : $dayTasks->take($chipLimit - 1);
    $hiddenCount = $dayTasks->count() - $visibleTasks->count();
    $dayKey = $day->toDateString();
    $dayRowKey = 'calendar|'.$dayKey;
    $dayTaskDraft = $this->taskDraft($dayRowKey);
    $dayDraftPriority = TaskPriority::from($dayTaskDraft['priority']);
    $dayDraftAssignees = collect($this->userOptions)->whereIn('id', collect($dayTaskDraft['assignees'])->map(fn ($id) => (int) $id));
@endphp

<div class="dk-tc-chips" x-data="taskDropList('task-calendar', (wire, id) => wire.rescheduleTask(id, '{{ $dayKey }}'))">
    @foreach ($visibleTasks as $task)
        @php
            $chipLabel = $markerLabel($task);
            $canReschedule = $task->created_by === auth()->id();
        @endphp
        <div
            data-task-chip
            @if ($canReschedule) data-task-id="{{ $task->id }}" @endif
            wire:key="task-calendar-chip-{{ $dayKey }}-{{ $task->id }}"
            title="{{ $task->title }}{{ $chipLabel !== null ? ' · '.$chipLabel : '' }}"
            class="dk-tc-chip"
            data-state="{{ $chipState($task) }}"
        >
            <span class="dk-tc-dot" data-priority="{{ $task->priority->value }}" aria-hidden="true"></span>
            <a href="{{ route('tasks.show', $task) }}" wire:navigate class="dk-tc-chip-title" data-test="task-calendar-chip-{{ $task->id }}">{{ $task->title }}</a>
            @if ($chipLabel !== null)
                <span class="dk-tc-flag" data-test="task-calendar-chip-badge-{{ $task->id }}">{{ $chipLabel }}</span>
            @endif
        </div>
    @endforeach
</div>

@if ($hiddenCount > 0)
    <div class="dk-tc-more" x-on:keydown.escape.window="moreOpen === {{ Js::from($dayKey) }} && (moreOpen = null)">
        <button
            type="button"
            class="dk-tc-more-btn"
            x-on:click="moreOpen = moreOpen === {{ Js::from($dayKey) }} ? null : {{ Js::from($dayKey) }}"
            x-bind:aria-expanded="moreOpen === {{ Js::from($dayKey) }} ? 'true' : 'false'"
            aria-controls="task-calendar-more-{{ $dayKey }}"
            data-test="task-calendar-more-{{ $dayKey }}"
        >{{ trans_choice('calendar.more_items', $hiddenCount, ['count' => $hiddenCount]) }}</button>

        <div
            id="task-calendar-more-{{ $dayKey }}"
            x-show="moreOpen === {{ Js::from($dayKey) }}"
            x-cloak
            x-transition.opacity.duration.100ms
            x-on:click.outside="moreOpen = null"
            role="dialog"
            aria-label="{{ __('tasks.calendar_tasks_on_day', ['date' => $day->translatedFormat('l, j F')]) }}"
            @class(['dk-tc-more-pop', 'is-end' => $alignEnd])
        >
            <div class="dk-tc-more-head">
                <span>{{ $day->translatedFormat('l, j F') }}</span>
                <button type="button" class="dk-tc-icon-btn" x-on:click="moreOpen = null" aria-label="{{ __('common.close') }}">
                    <flux:icon.x-mark variant="micro" class="size-4" />
                </button>
            </div>
            <ul class="dk-tc-more-list">
                @foreach ($dayTasks as $task)
                    @php $chipLabel = $markerLabel($task); @endphp
                    <li class="dk-tc-chip" data-state="{{ $chipState($task) }}" wire:key="task-calendar-more-{{ $dayKey }}-{{ $task->id }}">
                        <span class="dk-tc-dot" data-priority="{{ $task->priority->value }}" aria-hidden="true"></span>
                        <a href="{{ route('tasks.show', $task) }}" wire:navigate class="dk-tc-chip-title" data-test="task-calendar-more-item-{{ $task->id }}">{{ $task->title }}</a>
                        @if ($chipLabel !== null)
                            <span class="dk-tc-flag">{{ $chipLabel }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
@endif

@can('create', App\Models\Task::class)
<button
    type="button"
    x-show="!addOpen[{{ Js::from($dayKey) }}]"
    x-on:click="addOpen[{{ Js::from($dayKey) }}] = true; $nextTick(() => $refs['calendarAddInput{{ $day->toDateString() }}']?.focus())"
    class="dk-tc-add"
    :aria-label="{{ Js::from(__('tasks.add_task')) }}"
    data-test="task-calendar-add-toggle-{{ $day->toDateString() }}"
>
    <flux:icon.plus variant="micro" class="size-3 shrink-0" aria-hidden="true" />
    {{ __('tasks.add_task') }}
</button>

<div x-show="addOpen[{{ Js::from($dayKey) }}]" x-cloak class="dk-tc-add-row" data-test="task-calendar-add-row-{{ $day->toDateString() }}">
    <input
        type="text"
        x-ref="calendarAddInput{{ $day->toDateString() }}"
        placeholder="{{ __('tasks.add_task') }}"
        class="dk-tc-add-input"
        x-on:keydown.escape="addOpen[{{ Js::from($dayKey) }}] = false"
        wire:keydown.enter="quickCreateTask($event.target.value, null, null, {{ Js::from($dayRowKey) }}, {{ Js::from($day->toDateString()) }}); $event.target.value = ''"
        data-test="task-calendar-quick-add-{{ $day->toDateString() }}"
    />
    <div class="flex items-center gap-1">
        {{-- Trigger only: the option list lives once in _assignee-popover.blade.php. --}}
        <div
            x-data
            role="button"
            tabindex="0"
            x-on:click="$store.assigneePicker.openFor($el.dataset.test, {{ \Illuminate\Support\Js::from(collect($dayTaskDraft['assignees'])->map(fn ($id) => (int) $id)->values()->all()) }}, $el.getBoundingClientRect(), 'toggleTaskDraftAssignee', [{{ Js::from($dayRowKey) }}])"
            x-on:keydown.enter="$store.assigneePicker.openFor($el.dataset.test, {{ \Illuminate\Support\Js::from(collect($dayTaskDraft['assignees'])->map(fn ($id) => (int) $id)->values()->all()) }}, $el.getBoundingClientRect(), 'toggleTaskDraftAssignee', [{{ Js::from($dayRowKey) }}])"
            x-on:keydown.space.prevent="$store.assigneePicker.openFor($el.dataset.test, {{ \Illuminate\Support\Js::from(collect($dayTaskDraft['assignees'])->map(fn ($id) => (int) $id)->values()->all()) }}, $el.getBoundingClientRect(), 'toggleTaskDraftAssignee', [{{ Js::from($dayRowKey) }}])"
            aria-haspopup="listbox"
            aria-label="{{ __('tasks.assignees') }}"
            class="inline-flex shrink-0 cursor-pointer items-center -space-x-1.5 rtl:space-x-reverse"
            data-test="task-calendar-add-assignee-trigger-{{ $day->toDateString() }}"
        >
                @if ($dayDraftAssignees->isEmpty())
                    <span class="inline-flex size-5 items-center justify-center rounded-full border border-dashed border-secondary-light text-secondary-light">
                        <flux:icon.user-plus variant="micro" class="size-2.5" />
                    </span>
                @else
                    @foreach ($dayDraftAssignees->take(2) as $userOption)
                        <span class="inline-flex size-5 items-center justify-center rounded-full border-2 border-white bg-secondary-dark text-[8px] font-semibold text-white dark:border-surface-1 dark:bg-surface-3">
                            {{ Illuminate\Support\Str::of($userOption['name'])->explode(' ')->map(fn ($part) => mb_substr($part, 0, 1))->take(2)->implode('') }}
                        </span>
                    @endforeach
                    @if ($dayDraftAssignees->count() > 2)
                        <span class="inline-flex size-5 items-center justify-center rounded-full border-2 border-white bg-secondary-soft text-[8px] font-semibold text-secondary-dark dark:border-surface-1 dark:bg-surface-3 dark:text-ink">
                            +{{ $dayDraftAssignees->count() - 2 }}
                        </span>
                    @endif
                @endif
        </div>

        <flux:dropdown position="bottom" align="start">
            <button type="button" aria-label="{{ __('tasks.priority') }}: {{ $dayDraftPriority->label() }}" class="inline-flex size-5 shrink-0 cursor-pointer items-center justify-center rounded hover:bg-secondary-soft dark:hover:bg-surface-2" data-test="task-calendar-add-priority-trigger-{{ $day->toDateString() }}">
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
