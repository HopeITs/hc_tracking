{{--
    One task on the project's Tasks tab.

    Expects $task (with taskAssignees.employee loaded) plus the $inkText /
    $mutedText tone strings the tab passes down. Below the @3xl container
    width the columns fold into one meta line under the title; from @3xl the
    meta wrapper becomes `contents`, so each cell lands in the grid column
    the tab's column header names.
--}}
@php
    $assignees = $task->taskAssignees->pluck('employee')->filter()->values();
    $isDone = $task->status === \App\Enums\TaskStatus::Done;
    $isOverdue = ! $isDone && $task->due_date !== null && $task->due_date->lt(today());
    $progress = max(0, min(100, (int) $task->progress_percentage));
    // Progress and status are entered separately, so a finished task can still sit in an earlier column.
    $statusLags = $progress >= 100 && in_array($task->status, [\App\Enums\TaskStatus::Todo, \App\Enums\TaskStatus::InProgress], true);
@endphp

<li
    class="grid grid-cols-[1.25rem_minmax(0,1fr)] items-center gap-x-3 gap-y-2 py-3.5 @3xl/detail:grid-cols-[1.25rem_minmax(0,1fr)_11rem_6rem_7.5rem_8rem_7rem] @3xl/detail:gap-x-4"
    wire:key="task-{{ $task->id }}"
    data-task="{{ $task->id }}"
>
    {{-- The marker is a pie of the task's own progress; a finished task is filled solid. --}}
    <span
        @class([
            'size-3.5 rounded-full border-2 text-secondary-dark dark:text-ink',
            'border-secondary-dark bg-secondary-dark dark:border-ink-muted dark:bg-ink-muted' => $isDone,
            'border-primary text-status-critical!' => $isOverdue,
            'border-secondary-dark dark:border-ink-muted' => ! $isDone && ! $isOverdue && $progress > 0,
            'border-secondary-light' => ! $isDone && ! $isOverdue && $progress === 0,
        ])
        @unless ($isDone) style="background: conic-gradient(currentColor {{ $progress }}%, transparent 0)" @endunless
        aria-hidden="true"
    ></span>

    <div class="min-w-0">
        <a href="{{ route('tasks.show', $task) }}" wire:navigate @class([
            'block truncate text-sm font-semibold underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
            $mutedText.' line-through decoration-secondary-light' => $isDone,
            $inkText => ! $isDone,
        ])>{{ $task->title }}</a>
        <p class="mt-0.5 flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-xs {{ $mutedText }}">
            {{-- Redundant to name this project again on a project-scoped tab, so the source line only shows for milestone-sourced tasks. --}}
            @if ($task->source_type === 'milestone')
                <span class="min-w-0 truncate">{{ $task->sourceLabel() }}</span>
            @endif
            @if ($statusLags)
                @if ($task->source_type === 'milestone')
                    <span aria-hidden="true">·</span>
                @endif
                <span class="font-semibold text-status-warn" data-status-lags>{{ __('tasks.progress_complete_but_status_is', ['status' => $task->status->label()]) }}</span>
            @endif
        </p>
    </div>

    <div class="col-start-2 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs @3xl/detail:contents">
        {{-- Assignees: initials, the first name in full, the rest counted. --}}
        <div class="flex min-w-0 items-center gap-2">
            @if ($assignees->isEmpty())
                <span class="size-6 shrink-0 rounded-full border border-dashed border-secondary-light" aria-hidden="true"></span>
                <span class="truncate {{ $mutedText }}">{{ __('common.unassigned') }}</span>
            @else
                <span class="flex shrink-0 -space-x-1.5 rtl:space-x-reverse" aria-hidden="true">
                    @foreach ($assignees->take(2) as $employee)
                        <span class="inline-flex size-6 items-center justify-center rounded-full bg-secondary-soft text-[10px] font-bold text-secondary-dark ring-2 ring-white dark:bg-surface-3 dark:text-ink dark:ring-surface-1">
                            {{ mb_strtoupper(collect(preg_split('/\s+/u', trim($employee->name)))->filter()->take(2)->map(fn (string $part): string => mb_substr($part, 0, 1))->implode('')) }}
                        </span>
                    @endforeach
                </span>
                <span class="truncate font-medium {{ $inkText }}" title="{{ $assignees->map->name->implode(', ') }}">
                    {{ $assignees->first()->name }}@if ($assignees->count() > 1)<span class="{{ $mutedText }}"> +{{ $assignees->count() - 1 }}</span>@endif
                </span>
            @endif
        </div>

        <span @class([
            'inline-flex items-center gap-1',
            'font-bold text-status-critical' => $task->priority === \App\Enums\TaskPriority::High,
            'font-medium '.$inkText => $task->priority === \App\Enums\TaskPriority::Medium,
            $mutedText => $task->priority === \App\Enums\TaskPriority::Low,
        ])>
            @if ($task->priority === \App\Enums\TaskPriority::High)
                <flux:icon.arrow-up variant="micro" class="size-3.5 shrink-0" aria-hidden="true" />
            @elseif ($task->priority === \App\Enums\TaskPriority::Low)
                <flux:icon.arrow-down variant="micro" class="size-3.5 shrink-0" aria-hidden="true" />
            @else
                <flux:icon.minus variant="micro" class="size-3.5 shrink-0" aria-hidden="true" />
            @endif
            {{ $task->priority->label() }}
        </span>

        <span class="tabular-nums">
            @if ($task->due_date === null)
                <span class="{{ $mutedText }}">{{ __('tasks.no_due_date') }}</span>
            @else
                <time datetime="{{ $task->due_date->toDateString() }}" @class(['font-bold text-status-critical' => $isOverdue, $inkText => ! $isOverdue])>{{ $task->due_date->translatedFormat(__('formats.date')) }}</time>
                @if ($isOverdue)
                    <span class="block text-[10px] font-bold tracking-wide text-status-critical uppercase">{{ __('tasks.overdue') }}</span>
                @endif
            @endif
        </span>

        <span class="flex items-center gap-2" role="img" aria-label="{{ __('tasks.progress') }} {{ $progress }}%">
            <span class="h-1 w-14 overflow-hidden rounded-full bg-secondary-soft dark:bg-surface-2 @3xl/detail:flex-1" aria-hidden="true">
                <span class="block h-full rounded-full bg-secondary-dark dark:bg-ink-muted" style="width: {{ $progress }}%"></span>
            </span>
            <span class="w-9 text-end font-semibold tabular-nums {{ $progress >= 100 ? $inkText : $mutedText }}">{{ $progress }}%</span>
        </span>

        <span class="@3xl/detail:justify-self-end">
            <x-ui.enum-badge :case="$task->status" />
        </span>
    </div>
</li>
