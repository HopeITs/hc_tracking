@php
    use App\Enums\TaskPriority;
    use App\Enums\TaskStatus;
    use App\Support\FluxMarkup;
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
        $isOverdue, $isMissed => 'font-semibold text-primary dark:text-brand-text',
        $isDueSoon => 'font-medium text-status-warn',
        default => 'text-secondary-medium dark:text-ink-muted',
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
        'group/row outline-none transition-colors hover:bg-secondary-soft/40 focus-visible:bg-secondary-soft/40 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-secondary-medium dark:hover:bg-surface-2',
        'bg-secondary-soft/20 dark:bg-white/[0.03]' => $isSubtask,
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
            class="inline-flex size-6 cursor-pointer touch-manipulation items-center justify-center rounded-full text-secondary-medium hover:bg-secondary-soft disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:bg-transparent dark:text-ink-muted dark:hover:bg-surface-2"
            data-test="task-toggle-complete-{{ $task->id }}"
        >
            {{ FluxMarkup::icon('check-circle', $isDone ? 'solid' : 'outline', 'size-4.5 '.($isDone ? 'text-status-good' : '')) }}
        </button>
    </td>
    <td class="min-w-0 px-2 py-2.5">
        <div class="flex min-w-0 items-center gap-1.5 {{ $isSubtask ? 'ps-5' : '' }}">
            @if (! $isSubtask)
                <button
                    type="button"
                    x-on:click="subtasksOpen[{{ $task->id }}] = !subtasksOpen[{{ $task->id }}]"
                    :aria-expanded="!!subtasksOpen[{{ $task->id }}]"
                    class="inline-flex shrink-0 items-center gap-0.5 rounded px-1 py-0.5 text-xs text-secondary-medium hover:bg-secondary-soft hover:text-secondary-dark dark:text-ink-muted dark:hover:bg-surface-2 dark:hover:text-ink"
                    :aria-label="subtasksOpen[{{ $task->id }}] ? {{ Js::from(__('tasks.hide_subtasks')) }} : {{ Js::from(__('tasks.show_subtasks')) }}"
                    data-test="task-subtasks-toggle-{{ $task->id }}"
                >
                    <span class="inline-flex transition-transform rtl:rotate-180" x-bind:class="subtasksOpen[{{ $task->id }}] ? 'rotate-90 rtl:rotate-90' : ''">{{ FluxMarkup::icon('chevron-right', 'micro', 'size-3.5') }}</span>
                    @if ($task->subtasks_count > 0)
                        {{ FluxMarkup::icon('rectangle-stack', 'micro', 'size-3.5') }}
                        {{ $task->subtasks_count }}
                    @endif
                </button>
            @endif
            @if (in_array($task->id, $this->unreadIds, true))
                <span class="size-2 shrink-0 rounded-full bg-primary dark:bg-ink" data-unread-dot="task-{{ $task->id }}" title="{{ __('notifications.has_unread') }}"><span class="sr-only">{{ __('notifications.has_unread') }}</span></span>
            @endif
            <a
                href="{{ route('tasks.show', $task) }}"
                wire:navigate
                title="{{ $task->title }}"
                @class(['min-w-0 truncate text-sm font-semibold hover:underline', 'text-secondary-light line-through' => $isDone, 'text-secondary-dark dark:text-ink' => ! $isDone])
            >{{ $task->title }}</a>
        </div>
        @unless ($isSubtask)
            {{-- A subtask always shares its parent's source (see
                 addSubtaskQuick()), so repeating this line on every subtask
                 row would just be noise — the parent row right above already
                 says it. --}}
            <p class="truncate text-xs text-secondary-medium dark:text-ink-muted">{{ $task->sourceLabel() }}</p>
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
                    {{ FluxMarkup::icon('user-plus', 'micro', 'size-3.5') }}
                </span>
            @else
                @foreach ($assignees->take(3) as $employee)
                    <div class="group/avatar relative">
                        <span class="inline-flex size-7 items-center justify-center rounded-full bg-secondary-dark text-[11px] font-semibold text-white ring-2 ring-white transition-transform group-hover/row:ring-secondary-soft/40 dark:bg-surface-3 dark:ring-surface-1 dark:group-hover/row:ring-surface-2">
                            {{ Illuminate\Support\Str::of($employee->name)->explode(' ')->map(fn ($part) => mb_substr($part, 0, 1))->take(2)->implode('') }}
                        </span>
                        @if ($canUpdate)
                        <button
                            type="button"
                            wire:click.stop="toggleAssignee({{ $task->id }}, {{ $employee->id }})"
                            class="absolute -top-1 -end-1 hidden size-4 cursor-pointer items-center justify-center rounded-full bg-secondary-dark text-white hover:bg-primary dark:hover:bg-status-critical dark:hover:text-surface-0 group-hover/avatar:flex dark:bg-ink dark:text-surface-0"
                            aria-label="{{ __('tasks.remove_assignee_name', ['name' => $employee->name]) }}"
                            data-test="task-assignee-remove-{{ $task->id }}-{{ $employee->id }}"
                        >
                            {{ FluxMarkup::icon('x-mark', 'micro', 'size-2.5') }}
                        </button>
                        @endif
                    </div>
                @endforeach
                @if ($assignees->count() > 3)
                    <span class="inline-flex size-7 items-center justify-center rounded-full bg-secondary-soft text-[10px] font-semibold text-secondary-dark ring-2 ring-white dark:bg-surface-3 dark:text-ink dark:ring-surface-1">
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
                class="w-full min-w-0 rounded border-0 bg-transparent p-1 text-xs tabular-nums focus:ring-1 focus:ring-secondary-light focus:outline-none {{ $dueDateClasses }}"
                data-test="task-due-date-{{ $task->id }}"
            />
        </div>
    </td>
    <td class="px-2">
        @if (! $canUpdate)
            <span class="inline-flex size-7 items-center justify-center" aria-label="{{ __('tasks.priority') }}: {{ $task->priority->label() }}" data-test="task-priority-{{ $task->id }}">
                {{ FluxMarkup::priorityFlag($task->priority) }}
            </span>
        @else
            {{-- Trigger only — the menu lives once per page in _task-option-menu.blade.php (see task-menu.js). --}}
            <button
                type="button"
                x-data
                aria-haspopup="menu"
                x-on:click="$store.taskMenu.openFor('priority', 'priority-{{ $task->id }}', '{{ $task->priority->value }}', $el.getBoundingClientRect(), 'quickSetPriority', [{{ $task->id }}])"
                aria-label="{{ __('tasks.priority') }}: {{ $task->priority->label() }}"
                class="inline-flex size-7 cursor-pointer items-center justify-center rounded hover:bg-secondary-soft dark:hover:bg-surface-2"
                data-test="task-priority-trigger-{{ $task->id }}"
            >
                {{ FluxMarkup::priorityFlag($task->priority) }}
            </button>
        @endif
    </td>
    <td class="px-2">
        @if (! $canMove)
            {{ FluxMarkup::statusPill($task->status) }}
        @else
            <button
                type="button"
                x-data
                aria-haspopup="menu"
                x-on:click="$store.taskMenu.openFor('status', 'status-{{ $task->id }}', '{{ $task->status->value }}', $el.getBoundingClientRect(), 'quickSetStatus', [{{ $task->id }}])"
                class="cursor-pointer"
                data-test="task-status-trigger-{{ $task->id }}"
            >
                {{ FluxMarkup::statusPill($task->status) }}
            </button>
        @endif
    </td>
    <td class="pe-3 text-end opacity-0 group-hover/row:opacity-100 group-focus-within/row:opacity-100 focus-within:opacity-100">
        <div class="inline-flex items-center gap-0.5">
            @if (! $isSubtask && $canCreate)
                {{ FluxMarkup::ghostIconButton('plus', __('tasks.add_subtask'), 'x-on:click="subtasksOpen['.$task->id.'] = true; $nextTick(() => $refs[\'subtaskAddInput'.$task->id.'\']?.focus())" data-test="task-add-subtask-'.$task->id.'"') }}
            @endif
            @if ($canDelete)
                {{ FluxMarkup::ghostIconButton('trash', __('tasks.delete_title', ['title' => $task->title]), 'wire:click="confirmDelete('.$task->id.')"') }}
            @endif
        </div>
    </td>
</tr>
