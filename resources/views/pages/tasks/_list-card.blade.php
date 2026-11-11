@php
    use App\Enums\TaskPriority;
    use App\Enums\TaskStatus;
    use App\Support\FluxMarkup;
    use Illuminate\Support\Js;
    use Illuminate\Support\Str;

    /**
     * The mobile/tablet card for one task — reused as-is for a top-level
     * task and for each of its subtasks (indented under a connector line, no
     * expand toggle of its own), same as _list-row does for the desktop
     * table. Title first, then one line of facts (status, priority, due,
     * subtasks, people), then the touch-sized actions.
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
    $subtasksDone = $isSubtask ? 0 : $task->subtasks->where('status', TaskStatus::Done)->count();
    // What the viewer may do to this card (TaskPolicy).
    $canUpdate = auth()->user()->can('update', $task);
    $canMove = $canUpdate || auth()->user()->can('updateStatus', $task);
    $canDelete = auth()->user()->can('delete', $task);
    $canCreate = auth()->user()->can('create', App\Models\Task::class);
    $hasActions = (! $isSubtask && $canCreate) || $canUpdate || $canDelete;
@endphp

<article
    @class(['flex flex-col gap-2.5 px-3.5 py-3', 'bg-secondary-soft/20 dark:bg-white/[0.03]' => $isSubtask])
    wire:key="task-card-{{ $task->id }}"
    data-test="task-card-{{ $task->id }}"
    @if ($isSubtask)
        x-show="subtasksOpen[{{ $task->parent_task_id }}]" x-cloak
    @endif
>
    <div @class(['flex flex-col gap-2.5', 'ms-4 border-s-2 border-secondary-soft ps-3 dark:border-line' => $isSubtask])>
        <div class="flex items-start gap-2">
            <button
                type="button"
                wire:click="toggleComplete({{ $task->id }})"
                @disabled($isDone || ! $canMove)
                class="-ms-1.5 inline-flex size-11 shrink-0 cursor-pointer touch-manipulation items-center justify-center rounded-full text-secondary-medium hover:bg-secondary-soft focus-visible:outline-2 focus-visible:outline-secondary-medium disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:bg-transparent dark:text-ink-muted dark:hover:bg-surface-2"
                aria-label="{{ __('tasks.mark_complete') }}"
            >
                {{ FluxMarkup::icon('check-circle', $isDone ? 'solid' : 'outline', 'size-5 '.($isDone ? 'text-status-good' : '')) }}
            </button>
            <div class="min-w-0 flex-1 pt-2.5">
                <div class="flex min-w-0 items-center gap-1.5">
                    @if (in_array($task->id, $this->unreadIds, true))
                        <span class="size-2 shrink-0 rounded-full bg-primary dark:bg-ink" data-unread-dot="task-{{ $task->id }}" title="{{ __('notifications.has_unread') }}"><span class="sr-only">{{ __('notifications.has_unread') }}</span></span>
                    @endif
                    @if ($task->isReportTask())<x-ui.enum-badge :case="$task->kind" data-test="report-task-chip" />@endif
                    <a href="{{ $task->reportUrl() ?? route('tasks.show', $task) }}" wire:navigate dir="auto" title="{{ $task->title }}" @class([
                        'min-w-0 text-start line-clamp-2 break-words hover:underline',
                        'text-sm font-semibold' => ! $isSubtask,
                        'text-[13px] font-medium' => $isSubtask,
                        'text-secondary-light line-through' => $isDone,
                        'text-secondary-dark dark:text-ink' => ! $isDone,
                    ])>{{ $task->title }}</a>
                </div>
                @unless ($isSubtask)
                    <p dir="auto" class="mt-0.5 truncate text-start text-xs text-secondary-medium dark:text-ink-muted">{{ $task->sourceLabel() }}</p>
                @endunless
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-x-3 gap-y-2 ps-9">
            {{ FluxMarkup::statusPill($task->status, quiet: true) }}

            <span @class([
                'inline-flex items-center gap-1 text-xs',
                'font-semibold text-secondary-dark dark:text-ink' => $task->priority === TaskPriority::High,
                'text-secondary-dark dark:text-ink' => $task->priority === TaskPriority::Medium,
                'text-secondary-medium dark:text-ink-muted' => $task->priority === TaskPriority::Low,
            ])>
                {{ FluxMarkup::priorityFlag($task->priority, 'size-3.5') }}
                <span class="sr-only">{{ __('tasks.priority') }}: </span>{{ $task->priority->label() }}
            </span>

            @if ($task->due_date !== null)
                <span class="inline-flex min-w-0 items-center gap-1.5 text-xs">
                    <span @class([
                        'tabular-nums',
                        'font-semibold text-primary dark:text-brand-text' => ! $isDone && ($isOverdue || $isMissed),
                        'text-secondary-light' => $isDone,
                        'text-secondary-medium dark:text-ink-muted' => ! $isDone && ! $isOverdue && ! $isMissed,
                    ]) data-test="task-card-due-date-{{ $task->id }}"><span class="sr-only">{{ __('tasks.due_date_2') }}: </span>{{ $task->due_date->translatedFormat(__($task->due_date->isCurrentYear() ? 'formats.date_short' : 'formats.date')) }}</span>
                    @include('pages.tasks._list-due-note', ['task' => $task, 'today' => $today, 'hookSuffix' => '-mobile'])
                </span>
            @endif

            @if (! $isSubtask && $task->subtasks_count > 0)
                <button
                    type="button"
                    x-on:click="subtasksOpen[{{ $task->id }}] = !subtasksOpen[{{ $task->id }}]"
                    :aria-expanded="!!subtasksOpen[{{ $task->id }}]"
                    class="-my-2 inline-flex min-h-11 cursor-pointer touch-manipulation items-center gap-0.5 rounded px-1.5 text-xs tabular-nums text-secondary-medium hover:bg-secondary-soft focus-visible:outline-2 focus-visible:outline-secondary-medium dark:text-ink-muted dark:hover:bg-surface-2"
                    :aria-label="(subtasksOpen[{{ $task->id }}] ? {{ Js::from(__('tasks.hide_subtasks')) }} : {{ Js::from(__('tasks.show_subtasks')) }}) + ' — ' + {{ Js::from(__('tasks.subtasks_progress', ['done' => $subtasksDone, 'total' => $task->subtasks_count])) }}"
                    data-test="task-subtasks-toggle-mobile-{{ $task->id }}"
                >
                    <span class="inline-flex transition-transform rtl:rotate-180" x-bind:class="subtasksOpen[{{ $task->id }}] ? 'rotate-90 rtl:rotate-90' : ''">{{ FluxMarkup::icon('chevron-right', 'micro', 'size-3.5') }}</span>
                    <span dir="ltr">{{ $subtasksDone }}/{{ $task->subtasks_count }}</span>
                </button>
            @endif

            <span
                class="ms-auto inline-flex items-center -space-x-1.5 rtl:space-x-reverse"
                aria-label="{{ __('tasks.assignees') }}: {{ $cardAssignees->isEmpty() ? __('common.not_assigned') : $cardAssignees->map->name->implode(', ') }}"
                role="img"
            >
                @forelse ($cardAssignees->take(3) as $employee)
                    <span class="inline-flex size-7 items-center justify-center rounded-full bg-secondary-dark text-[11px] font-semibold text-white ring-2 ring-white dark:bg-surface-3 dark:ring-surface-1" title="{{ $employee->name }}">
                        {{ Str::of($employee->name)->explode(' ')->map(fn ($part) => mb_substr($part, 0, 1))->take(2)->implode('') }}
                    </span>
                @empty
                    <span class="inline-flex size-7 items-center justify-center rounded-full border border-dashed border-secondary-light text-secondary-light" title="{{ __('common.not_assigned') }}">
                        {{ FluxMarkup::icon('user-plus', 'micro', 'size-3.5') }}
                    </span>
                @endforelse
                @if ($cardAssignees->count() > 3)
                    <span class="inline-flex size-7 items-center justify-center rounded-full bg-secondary-soft text-[10px] font-semibold text-secondary-dark ring-2 ring-white dark:bg-surface-3 dark:text-ink dark:ring-surface-1">+{{ $cardAssignees->count() - 3 }}</span>
                @endif
            </span>
        </div>

        @if ($hasActions)
            <div class="-mb-1.5 -mt-1 flex items-center justify-end gap-0.5">
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
        @endif
    </div>
</article>
