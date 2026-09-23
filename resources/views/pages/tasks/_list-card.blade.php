@php
    use App\Enums\TaskPriority;
    use App\Enums\TaskStatus;

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
    $isOverdue = ! $isDone && $task->due_date !== null && $task->due_date->lt($today);
    $cardAssignees = $task->taskAssignees->pluck('employee')->filter();
    $priorityBorderClass = match ($task->priority) {
        TaskPriority::High => 'border-primary dark:border-status-critical',
        TaskPriority::Medium => 'border-status-warn',
        TaskPriority::Low => 'border-transparent',
    };
@endphp

<article
    @class(['flex flex-col gap-3 border-s-[3px] p-3.5', $priorityBorderClass, 'bg-secondary-soft/20 dark:bg-secondary-medium/10' => $isSubtask])
    wire:key="task-card-{{ $task->id }}"
    @if ($isSubtask)
        x-show="subtasksOpen[{{ $task->parent_task_id }}]" x-cloak
    @endif
>
    <div class="flex items-start gap-3 {{ $isSubtask ? 'ps-6' : '' }}">
        <button
            type="button"
            wire:click="toggleComplete({{ $task->id }})"
            class="inline-flex size-9 shrink-0 cursor-pointer touch-manipulation items-center justify-center rounded-full text-secondary-medium hover:bg-secondary-soft dark:text-secondary-soft dark:hover:bg-secondary-medium"
            aria-label="{{ $isDone ? __('tasks.mark_incomplete') : __('tasks.mark_complete') }}"
        >
            <flux:icon.check-circle :variant="$isDone ? 'solid' : 'outline'" class="size-5 {{ $isDone ? 'text-status-good' : '' }}" />
        </button>
        <div class="min-w-0 flex-1">
            <a href="{{ route('tasks.show', $task) }}" wire:navigate title="{{ $task->title }}" @class(['line-clamp-2 text-sm font-semibold break-words hover:underline', 'text-secondary-light line-through' => $isDone])>{{ $task->title }}</a>
            @unless ($isSubtask)
                <p class="truncate text-xs text-secondary-medium dark:text-secondary-soft">{{ $task->sourceLabel() }}</p>
            @endunless
        </div>
    </div>
    <div class="flex flex-wrap items-center gap-2 ps-12">
        <x-ui.status-pill :case="$task->status" />
        <flux:icon.flag :variant="$task->priority === TaskPriority::Low ? 'outline' : 'solid'" class="size-4 {{ $priorityIconClasses($task->priority) }}" />
        @if (! $isSubtask)
            <button
                type="button"
                x-on:click="subtasksOpen[{{ $task->id }}] = !subtasksOpen[{{ $task->id }}]"
                :aria-expanded="!!subtasksOpen[{{ $task->id }}]"
                class="inline-flex items-center gap-0.5 rounded px-1 py-0.5 text-xs text-secondary-medium hover:bg-secondary-soft dark:text-secondary-soft dark:hover:bg-secondary-medium"
                :aria-label="subtasksOpen[{{ $task->id }}] ? {{ Illuminate\Support\Js::from(__('tasks.hide_subtasks')) }} : {{ Illuminate\Support\Js::from(__('tasks.show_subtasks')) }}"
                data-test="task-subtasks-toggle-mobile-{{ $task->id }}"
            >
                <flux:icon.rectangle-stack variant="micro" class="size-3.5" aria-hidden="true" />
                {{ $task->subtasks_count }}
            </button>
        @endif
        @if ($task->due_date !== null)
            <span @class(['text-xs tabular-nums', 'font-semibold text-primary' => $isOverdue, 'text-secondary-medium dark:text-secondary-soft' => ! $isOverdue])>
                {{ $task->due_date->translatedFormat(__('formats.date_short')) }}
            </span>
        @endif
        @if ($cardAssignees->isEmpty())
            <x-ui.employee-chip :employee="null" compact class="ms-auto" />
        @else
            <span class="ms-auto truncate text-xs text-secondary-medium dark:text-secondary-soft">{{ $cardAssignees->map->name->implode(', ') }}</span>
        @endif
    </div>
    <div class="flex items-center justify-end gap-1 ps-12">
        @if (! $isSubtask)
            <x-ui.button
                tone="ghost"
                size="sm"
                icon="plus"
                class="size-11! touch-manipulation"
                x-on:click="subtasksOpen[{{ $task->id }}] = true; $nextTick(() => $refs['subtaskAddInputMobile{{ $task->id }}']?.focus())"
                :aria-label="__('tasks.add_subtask')"
                data-test="task-add-subtask-mobile-{{ $task->id }}"
            />
        @endif
        <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-11! touch-manipulation" wire:click="openEditDrawer({{ $task->id }})" :aria-label="__('tasks.edit_title', ['title' => $task->title])" />
        <x-ui.button tone="ghost" size="sm" icon="trash" class="size-11! touch-manipulation" wire:click="confirmDelete({{ $task->id }})" :aria-label="__('tasks.delete_title', ['title' => $task->title])" />
    </div>
</article>
