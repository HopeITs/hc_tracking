@php
    use App\Enums\TaskPriority;
    use App\Enums\TaskStatus;
    use App\Support\FluxMarkup;

    /**
     * The mobile/tablet card fallback for one task row — reused as-is for a
     * top-level task and for each of its subtasks (indented, no expand
     * toggle of its own), same as _list-row does for the desktop table.
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
    $cardAssignees = $task->taskAssignees->pluck('employee')->filter();
    // What the viewer may do to this card (TaskPolicy).
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

<article
    @class(['flex flex-col gap-3 border-s-[3px] p-3.5', $priorityBorderClass, 'bg-secondary-soft/20 dark:bg-white/[0.03]' => $isSubtask])
    wire:key="task-card-{{ $task->id }}"
    @if ($isSubtask)
        x-show="subtasksOpen[{{ $task->parent_task_id }}]" x-cloak
    @endif
>
    <div class="flex items-start gap-3 {{ $isSubtask ? 'ps-6' : '' }}">
        <button
            type="button"
            wire:click="toggleComplete({{ $task->id }})"
            @disabled($isDone || ! $canMove)
            class="inline-flex size-9 shrink-0 cursor-pointer touch-manipulation items-center justify-center rounded-full text-secondary-medium hover:bg-secondary-soft disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:bg-transparent dark:text-ink-muted dark:hover:bg-surface-2"
            aria-label="{{ __('tasks.mark_complete') }}"
        >
            {{ FluxMarkup::icon('check-circle', $isDone ? 'solid' : 'outline', 'size-5 '.($isDone ? 'text-status-good' : '')) }}
        </button>
        <div class="min-w-0 flex-1">
            <div class="flex min-w-0 items-center gap-1.5">
                @if (in_array($task->id, $this->unreadIds, true))
                    <span class="size-2 shrink-0 rounded-full bg-primary dark:bg-ink" data-unread-dot="task-{{ $task->id }}" title="{{ __('notifications.has_unread') }}"><span class="sr-only">{{ __('notifications.has_unread') }}</span></span>
                @endif
                <a href="{{ route('tasks.show', $task) }}" wire:navigate title="{{ $task->title }}" @class(['min-w-0 line-clamp-2 text-sm font-semibold break-words hover:underline', 'text-secondary-light line-through' => $isDone])>{{ $task->title }}</a>
            </div>
            @unless ($isSubtask)
                <p class="truncate text-xs text-secondary-medium dark:text-ink-muted">{{ $task->sourceLabel() }}</p>
            @endunless
        </div>
    </div>
    <div class="flex flex-wrap items-center gap-2 ps-12">
        {{ FluxMarkup::statusPill($task->status) }}
        {{ FluxMarkup::priorityFlag($task->priority) }}
        @if (! $isSubtask)
            <button
                type="button"
                x-on:click="subtasksOpen[{{ $task->id }}] = !subtasksOpen[{{ $task->id }}]"
                :aria-expanded="!!subtasksOpen[{{ $task->id }}]"
                class="inline-flex items-center gap-0.5 rounded px-1 py-0.5 text-xs text-secondary-medium hover:bg-secondary-soft dark:text-ink-muted dark:hover:bg-surface-2"
                :aria-label="subtasksOpen[{{ $task->id }}] ? {{ Illuminate\Support\Js::from(__('tasks.hide_subtasks')) }} : {{ Illuminate\Support\Js::from(__('tasks.show_subtasks')) }}"
                data-test="task-subtasks-toggle-mobile-{{ $task->id }}"
            >
                {{ FluxMarkup::icon('rectangle-stack', 'micro', 'size-3.5') }}
                {{ $task->subtasks_count }}
            </button>
        @endif
        @if ($isMissed)
            <span class="inline-flex items-center rounded-full bg-status-critical-soft px-1.5 py-0.5 text-[10px] font-bold tracking-wide text-primary uppercase dark:text-status-critical" data-test="task-missed-mobile-{{ $task->id }}">{{ __('tasks.missed') }}</span>
        @elseif ($isOverdue)
            <span class="inline-flex items-center rounded-full bg-status-critical-soft px-1.5 py-0.5 text-[10px] font-bold tracking-wide text-primary uppercase dark:text-status-critical" data-test="task-overdue-mobile-{{ $task->id }}">{{ __('tasks.overdue') }}</span>
        @endif
        @if ($task->due_date !== null)
            <span @class(['text-xs tabular-nums', 'font-semibold text-primary dark:text-brand-text' => $isOverdue || $isMissed, 'text-secondary-medium dark:text-ink-muted' => ! $isOverdue && ! $isMissed])>
                {{ $task->due_date->translatedFormat(__('formats.date_short')) }}
            </span>
        @endif
        @if ($cardAssignees->isEmpty())
            <x-ui.employee-chip :employee="null" compact class="ms-auto" />
        @else
            <span class="ms-auto truncate text-xs text-secondary-medium dark:text-ink-muted">{{ $cardAssignees->map->name->implode(', ') }}</span>
        @endif
    </div>
    <div class="flex items-center justify-end gap-1 ps-12">
        @if (! $isSubtask && $canCreate)
            {{ FluxMarkup::ghostIconButton('plus', __('tasks.add_subtask'), 'x-on:click="subtasksOpen['.$task->id.'] = true; $nextTick(() => $refs[\'subtaskAddInputMobile'.$task->id.'\']?.focus())" data-test="task-add-subtask-mobile-'.$task->id.'"', 'size-11! touch-manipulation') }}
        @endif
        @if ($canUpdate)
            {{ FluxMarkup::ghostIconButton('pencil-square', __('tasks.edit_title', ['title' => $task->title]), 'wire:click="openEditDrawer('.$task->id.')"', 'size-11! touch-manipulation') }}
        @endif
        @if ($canDelete)
            {{ FluxMarkup::ghostIconButton('trash', __('tasks.delete_title', ['title' => $task->title]), 'wire:click="confirmDelete('.$task->id.')"', 'size-11! touch-manipulation') }}
        @endif
    </div>
</article>
