{{--
    One task line in the proposal page's sections (stage 3b): name (opens the
    task), who · start – due, "late" in red, and a status pill. When
    $editable (a department task the viewer may plan) the pill opens the
    existing task form and an assign button sits beside it.
    Expects $task (taskAssignees.employee loaded), $taskStatusClass, $today, $editable.
--}}
@php
    $isDone = $task->status === \App\Enums\TaskStatus::Done;
    $isLate = ! $isDone && $task->due_date !== null && $task->due_date->lt($today);
    $who = $task->taskAssignees->pluck('employee')->filter()->map->name->implode(', ');
    $departmentName = $task->department_id !== null ? \App\Models\HrDepartment::nameOf((int) $task->department_id) : '';
    // Progress and status are entered separately, so a finished task can still sit in an earlier status.
    $statusLags = (int) $task->progress_percentage >= 100 && in_array($task->status, [\App\Enums\TaskStatus::Todo, \App\Enums\TaskStatus::InProgress], true);
@endphp
<div class="pp-lrow" wire:key="sec-task-{{ $task->id }}" data-task="{{ $task->id }}">
    <span class="pp-lrow-s">
        <a href="{{ route('tasks.show', $task) }}" wire:navigate dir="auto"><b>{{ $task->title }}</b></a>
        <span class="pp-small">
            @if ($departmentName !== '')
                <span dir="auto" data-department="{{ $task->department_id }}">{{ $departmentName }}</span> ·
            @endif
            <span dir="auto">{{ $who !== '' ? $who : __('common.unassigned') }}</span>
            @if ($task->start_date !== null || $task->due_date !== null)
                · <span class="tabular-nums">
                    @if ($task->start_date !== null)
                        {{ $task->start_date->translatedFormat(__('formats.date_short')) }} –
                    @endif
                    {{ $task->due_date?->translatedFormat(__('formats.date')) ?? __('proposals.no_due_date') }}
                </span>
            @endif
            @if ($isLate)
                · <span class="pp-red-text">{{ __('proposals.sections.late_word') }}</span>
            @endif
            @if ($statusLags)
                · <span class="pp-ink-text" data-status-lags>{{ __('proposals.progress_complete_but_status_is', ['status' => $task->status->label()]) }}</span>
            @endif
        </span>
    </span>
    <span class="pp-lrow-r">
        @if ($editable)
            <button type="button" class="pp-status {{ $taskStatusClass[$task->status->value] }}" wire:click="openPlanTaskForm({{ $task->id }})" aria-label="{{ __('department_tasks.edit_task') }}: {{ $task->status->label() }}" data-test="edit-plan-task-{{ $task->id }}">{{ $task->status->label() }}</button>
            <button type="button" class="pp-btn pp-btn-ghost pp-btn-sm" wire:click="openAssignModal({{ $task->id }})" aria-label="{{ __('department_tasks.assign_person') }}" data-test="assign-plan-task-{{ $task->id }}">
                <flux:icon.user-plus variant="micro" class="size-4" aria-hidden="true" />
            </button>
        @else
            <span class="pp-status {{ $taskStatusClass[$task->status->value] }}">{{ $task->status->label() }}</span>
        @endif
    </span>
</div>
