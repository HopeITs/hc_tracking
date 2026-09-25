@php
    use App\Enums\TaskPriority;
    use App\Enums\TaskStatus;
    use Illuminate\Support\Js;

    /**
     * A single task row, reused as-is for a top-level task and for each of
     * its subtasks (indented, no expand toggle of its own — subtasks are one
     * level deep) — so a subtask is editable exactly the same way its parent
     * is, not a scaled-down summary.
     *
     * @var \App\Models\Task $task
     * @var \Carbon\CarbonImmutable $today
     * @var \Closure $priorityIconClasses
     * @var bool $isSubtask
     */
    $isSubtask ??= false;
    $isDone = $task->status === TaskStatus::Done;
    $isOverdue = $task->isOverdue();
    $isMissed = $task->isMissed();
    $isDueSoon = ! $isDone && ! $isOverdue && ! $isMissed && $task->due_date !== null && $task->due_date->between($today, $today->addDay());
    $dueDateClasses = match (true) {
        $isDone => 'text-secondary-light line-through',
        $isOverdue, $isMissed => 'font-semibold text-primary',
        $isDueSoon => 'font-medium text-status-warn',
        default => 'text-secondary-medium dark:text-secondary-soft',
    };
    $assignedIds = $task->taskAssignees->pluck('employee_id')->all();
    $assignees = $task->taskAssignees->pluck('employee')->filter();
    // What the viewer may do to this row (TaskPolicy): editors change every
    // field, assignees only move status, and anything else is shown read-only.
    $canUpdate = auth()->user()->can('update', $task);
    $canMove = $canUpdate || auth()->user()->can('updateStatus', $task);
    $canDelete = auth()->user()->can('delete', $task);
    $canCreate = auth()->user()->can('create', App\Models\Task::class);
    $priorityBorderClass = match ($task->priority) {
        TaskPriority::High => 'border-primary dark:border-status-critical',
        TaskPriority::Medium => 'border-status-warn',
        TaskPriority::Low => 'border-transparent',
    };
@endphp

<tr
    tabindex="0"
    wire:key="task-table-row-{{ $task->id }}"
    data-test="task-row-{{ $task->id }}"
    @class([
        'group/row outline-none transition-colors hover:bg-secondary-soft/40 focus-visible:bg-secondary-soft/40 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary dark:hover:bg-secondary-medium/30',
        'bg-secondary-soft/20 dark:bg-secondary-medium/10' => $isSubtask,
    ])
    @if ($isSubtask)
        x-show="subtasksOpen[{{ $task->parent_task_id }}]" x-cloak
    @endif
    x-on:keydown.down.prevent="$event.currentTarget.nextElementSibling?.focus()"
    x-on:keydown.up.prevent="$event.currentTarget.previousElementSibling?.focus()"
    x-on:keydown.enter="if (!$event.target.closest('button, a, input')) { window.location.href = {{ Js::from(route('tasks.show', $task)) }} }"
>
    <td class="border-s-[3px] ps-2.5 {{ $priorityBorderClass }}">
        <button
            type="button"
            wire:click="toggleComplete({{ $task->id }})"
            @disabled($isDone || ! $canMove)
            aria-label="{{ __('tasks.mark_complete') }}"
            class="inline-flex size-6 cursor-pointer touch-manipulation items-center justify-center rounded-full text-secondary-medium hover:bg-secondary-soft disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:bg-transparent dark:text-secondary-soft dark:hover:bg-secondary-medium"
            data-test="task-toggle-complete-{{ $task->id }}"
        >
            <flux:icon.check-circle :variant="$isDone ? 'solid' : 'outline'" class="size-4.5 {{ $isDone ? 'text-status-good' : '' }}" />
        </button>
    </td>
    <td class="min-w-0 px-2 py-2.5">
        <div class="flex min-w-0 items-center gap-1.5 {{ $isSubtask ? 'ps-5' : '' }}">
            @if (! $isSubtask)
                <button
                    type="button"
                    x-on:click="subtasksOpen[{{ $task->id }}] = !subtasksOpen[{{ $task->id }}]"
                    :aria-expanded="!!subtasksOpen[{{ $task->id }}]"
                    class="inline-flex shrink-0 items-center gap-0.5 rounded px-1 py-0.5 text-xs text-secondary-medium hover:bg-secondary-soft hover:text-secondary-dark dark:text-secondary-soft dark:hover:bg-secondary-medium dark:hover:text-white"
                    :aria-label="subtasksOpen[{{ $task->id }}] ? {{ Js::from(__('tasks.hide_subtasks')) }} : {{ Js::from(__('tasks.show_subtasks')) }}"
                    data-test="task-subtasks-toggle-{{ $task->id }}"
                >
                    <flux:icon.chevron-right variant="micro" class="size-3.5 transition-transform rtl:rotate-180" x-bind:class="subtasksOpen[{{ $task->id }}] ? 'rotate-90 rtl:rotate-90' : ''" aria-hidden="true" />
                    @if ($task->subtasks_count > 0)
                        <flux:icon.rectangle-stack variant="micro" class="size-3.5" aria-hidden="true" />
                        {{ $task->subtasks_count }}
                    @endif
                </button>
            @endif
            @if (in_array($task->id, $this->unreadIds, true))
                <span class="size-2 shrink-0 rounded-full bg-primary" data-unread-dot="task-{{ $task->id }}" title="{{ __('notifications.has_unread') }}"><span class="sr-only">{{ __('notifications.has_unread') }}</span></span>
            @endif
            <a
                href="{{ route('tasks.show', $task) }}"
                wire:navigate
                title="{{ $task->title }}"
                @class(['min-w-0 truncate text-sm font-semibold hover:underline', 'text-secondary-light line-through' => $isDone, 'text-secondary-dark dark:text-white' => ! $isDone])
            >{{ $task->title }}</a>
        </div>
        @unless ($isSubtask)
            {{-- A subtask always shares its parent's source (see
                 addSubtaskQuick()), so repeating this line on every subtask
                 row would just be noise — the parent row right above already
                 says it. --}}
            <p class="truncate text-xs text-secondary-medium dark:text-secondary-soft">{{ $task->sourceLabel() }}</p>
        @endunless
    </td>
    <td class="px-2">
        {{--
            Trigger only — the option list (every HrEmployee) lives once in
            _assignee-popover.blade.php via the assigneePicker Alpine store,
            instead of being re-rendered here per row. See assignee-picker.js
            for why that used to make this dropdown slow.
        --}}
        <div
            x-data
            role="button"
            @if ($canUpdate)
            tabindex="0"
            x-on:click="$store.assigneePicker.openFor({{ $task->id }}, {{ Js::from($assignedIds) }}, $el.getBoundingClientRect())"
            x-on:keydown.enter="$store.assigneePicker.openFor({{ $task->id }}, {{ Js::from($assignedIds) }}, $el.getBoundingClientRect())"
            x-on:keydown.space.prevent="$store.assigneePicker.openFor({{ $task->id }}, {{ Js::from($assignedIds) }}, $el.getBoundingClientRect())"
            @endif
            aria-haspopup="listbox"
            x-bind:aria-expanded="$store.assigneePicker.open && $store.assigneePicker.taskId === {{ $task->id }} ? 'true' : 'false'"
            aria-label="{{ __('tasks.assignees') }}: {{ $assignees->isEmpty() ? __('common.not_assigned') : $assignees->map->name->implode(', ') }}"
            class="inline-flex cursor-pointer items-center -space-x-2 rtl:space-x-reverse"
            data-test="task-assignee-trigger-{{ $task->id }}"
        >
            @if ($assignees->isEmpty())
                <span class="inline-flex size-7 items-center justify-center rounded-full border border-dashed border-secondary-light text-secondary-light">
                    <flux:icon.user-plus variant="micro" class="size-3.5" />
                </span>
            @else
                @foreach ($assignees->take(3) as $employee)
                    <div class="group/avatar relative">
                        <span class="inline-flex size-7 items-center justify-center rounded-full bg-secondary-dark text-[11px] font-semibold text-white ring-2 ring-white transition-transform group-hover/row:ring-secondary-soft/40 dark:bg-secondary-medium dark:ring-secondary-dark dark:group-hover/row:ring-secondary-medium/40">
                            {{ Illuminate\Support\Str::of($employee->name)->explode(' ')->map(fn ($part) => mb_substr($part, 0, 1))->take(2)->implode('') }}
                        </span>
                        @if ($canUpdate)
                        <button
                            type="button"
                            wire:click.stop="toggleAssignee({{ $task->id }}, {{ $employee->id }})"
                            class="absolute -top-1 -end-1 hidden size-4 cursor-pointer items-center justify-center rounded-full bg-secondary-dark text-white hover:bg-primary group-hover/avatar:flex dark:bg-secondary-medium"
                            aria-label="{{ __('tasks.remove_assignee_name', ['name' => $employee->name]) }}"
                            data-test="task-assignee-remove-{{ $task->id }}-{{ $employee->id }}"
                        >
                            <flux:icon.x-mark variant="micro" class="size-2.5" />
                        </button>
                        @endif
                    </div>
                @endforeach
                @if ($assignees->count() > 3)
                    <span class="inline-flex size-7 items-center justify-center rounded-full bg-secondary-soft text-[10px] font-semibold text-secondary-dark ring-2 ring-white dark:bg-secondary-medium dark:text-white dark:ring-secondary-dark">
                        +{{ $assignees->count() - 3 }}
                    </span>
                @endif
            @endif
        </div>
    </td>
    <td class="px-2">
        <div class="flex items-center gap-1">
            @if ($isMissed)
                <span class="inline-flex shrink-0 items-center rounded-full bg-status-critical-soft px-1.5 py-0.5 text-[10px] font-bold tracking-wide text-primary uppercase dark:text-status-critical" data-test="task-missed-{{ $task->id }}">{{ __('tasks.missed') }}</span>
            @elseif ($isOverdue)
                <span class="inline-flex shrink-0 items-center rounded-full bg-status-critical-soft px-1.5 py-0.5 text-[10px] font-bold tracking-wide text-primary uppercase dark:text-status-critical" data-test="task-overdue-{{ $task->id }}">{{ __('tasks.overdue') }}</span>
            @endif
            <input
                type="date"
                value="{{ $task->due_date?->toDateString() }}"
                wire:change="rescheduleTask({{ $task->id }}, $event.target.value)"
                @disabled(! $canUpdate)
                aria-label="{{ __('tasks.due_date_2') }}: {{ $task->title }}"
                class="w-full min-w-0 rounded border-0 bg-transparent p-1 text-xs tabular-nums focus:ring-1 focus:ring-primary focus:outline-none {{ $dueDateClasses }}"
                data-test="task-due-date-{{ $task->id }}"
            />
        </div>
    </td>
    <td class="px-2">
        @if (! $canUpdate)
            <span class="inline-flex size-7 items-center justify-center" aria-label="{{ __('tasks.priority') }}: {{ $task->priority->label() }}" data-test="task-priority-{{ $task->id }}">
                <flux:icon.flag :variant="$task->priority === TaskPriority::Low ? 'outline' : 'solid'" class="size-4 {{ $priorityIconClasses($task->priority) }}" />
            </span>
        @else
        <flux:dropdown position="bottom" align="start">
            <button type="button" aria-label="{{ __('tasks.priority') }}: {{ $task->priority->label() }}" class="inline-flex size-7 cursor-pointer items-center justify-center rounded hover:bg-secondary-soft dark:hover:bg-secondary-medium" data-test="task-priority-trigger-{{ $task->id }}">
                <flux:icon.flag :variant="$task->priority === TaskPriority::Low ? 'outline' : 'solid'" class="size-4 {{ $priorityIconClasses($task->priority) }}" />
            </button>
            <flux:menu>
                @foreach (TaskPriority::cases() as $priorityCase)
                    <flux:menu.item wire:click="quickSetPriority({{ $task->id }}, '{{ $priorityCase->value }}')" :wire:key="'task-'.$task->id.'-priority-'.$priorityCase->value">
                        <flux:icon.flag :variant="$priorityCase === TaskPriority::Low ? 'outline' : 'solid'" class="size-4 shrink-0 {{ $priorityIconClasses($priorityCase) }}" />
                        <span class="min-w-0 truncate">{{ $priorityCase->label() }}</span>
                        @if ($task->priority === $priorityCase)<flux:icon.check class="ms-auto size-4 shrink-0 text-secondary-medium" />@endif
                    </flux:menu.item>
                @endforeach
            </flux:menu>
        </flux:dropdown>
        @endif
    </td>
    <td class="px-2">
        @if (! $canMove)
            <x-ui.status-pill :case="$task->status" />
        @else
        <flux:dropdown position="bottom" align="start">
            <button type="button" class="cursor-pointer" data-test="task-status-trigger-{{ $task->id }}">
                <x-ui.status-pill :case="$task->status" />
            </button>
            <flux:menu>
                @foreach (TaskStatus::cases() as $statusCase)
                    <flux:menu.item wire:click="quickSetStatus({{ $task->id }}, '{{ $statusCase->value }}')" :wire:key="'task-'.$task->id.'-status-'.$statusCase->value" :data-test="'task-status-option-'.$task->id.'-'.$statusCase->value">
                        <x-ui.status-pill :case="$statusCase" />
                        @if ($task->status === $statusCase)<flux:icon.check class="ms-auto size-4 shrink-0 text-secondary-medium" />@endif
                    </flux:menu.item>
                @endforeach
            </flux:menu>
        </flux:dropdown>
        @endif
    </td>
    <td class="pe-3 text-end opacity-0 group-hover/row:opacity-100 group-focus-within/row:opacity-100 focus-within:opacity-100">
        <div class="inline-flex items-center gap-0.5">
            @if (! $isSubtask && $canCreate)
                <x-ui.button
                    tone="ghost"
                    size="sm"
                    icon="plus"
                    class="size-8!"
                    x-on:click="subtasksOpen[{{ $task->id }}] = true; $nextTick(() => $refs['subtaskAddInput{{ $task->id }}']?.focus())"
                    :aria-label="__('tasks.add_subtask')"
                    data-test="task-add-subtask-{{ $task->id }}"
                />
            @endif
            @if ($canDelete)
                <x-ui.button tone="ghost" size="sm" icon="trash" class="size-8!" wire:click="confirmDelete({{ $task->id }})" :aria-label="__('tasks.delete_title', ['title' => $task->title])" />
            @endif
        </div>
    </td>
</tr>
