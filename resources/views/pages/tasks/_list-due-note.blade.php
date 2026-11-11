@php
    use App\Enums\TaskStatus;

    /**
     * The small line under a task's due date in the list (table row and
     * mobile card): how late it is, or that it is due today or tomorrow —
     * spelled out, so the date's colour is never the only signal. Done tasks
     * and tasks without a date say nothing here.
     *
     * @var \App\Models\Task $task
     * @var \Carbon\CarbonImmutable $today
     * @var string $hookSuffix '' for the table row, '-mobile' for the card
     */
    $hookSuffix ??= '';
    $dueNoteDate = $task->due_date?->toDateString();
    $daysLate = $task->due_date !== null ? (int) abs($task->due_date->copy()->startOfDay()->diffInDays($today)) : 0;
@endphp

@if ($dueNoteDate !== null && $task->status !== TaskStatus::Done)
    @if ($task->isMissed())
        <span class="block truncate text-xs font-semibold text-primary dark:text-brand-text" data-test="task-missed{{ $hookSuffix }}-{{ $task->id }}">{{ __('tasks.missed') }} · {{ trans_choice('tasks.days_late', $daysLate, ['count' => $daysLate]) }}</span>
    @elseif ($task->isOverdue())
        <span class="block truncate text-xs font-semibold text-primary dark:text-brand-text" data-test="task-overdue{{ $hookSuffix }}-{{ $task->id }}">{{ trans_choice('tasks.days_late', $daysLate, ['count' => $daysLate]) }}</span>
    @elseif ($dueNoteDate === $today->toDateString())
        <span class="block truncate text-xs font-semibold text-status-warn">{{ __('common.today') }}</span>
    @elseif ($dueNoteDate === $today->addDay()->toDateString())
        <span class="block truncate text-xs text-secondary-medium dark:text-ink-muted">{{ __('tasks.due_tomorrow') }}</span>
    @endif
@endif
