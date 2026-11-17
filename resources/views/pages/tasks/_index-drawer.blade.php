{{--
    The create/edit task dialog of tasks/index, with the locks it derives from
    the draft (done status, past due date, creator-only due date and proof). Included by
    ⚡index.blade.php. The dialog itself is x-tasks.composer, shared with the
    calendar page.
--}}
@use('App\Enums\TaskStatus')
    @php
        $isStatusLocked = $editingTaskId !== null && $taskStatus === TaskStatus::Done->value;
        $isDueDatePast = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate) === 1
            && \Carbon\CarbonImmutable::parse($dueDate)->lt(\Carbon\CarbonImmutable::today());
        $isMissedDraft = $isDueDatePast && $taskStatus === TaskStatus::Todo->value;
        $isOverdueDraft = $isDueDatePast && ! $isMissedDraft && $taskStatus !== TaskStatus::Done->value;
        $isDueDateLocked = $editingTaskId !== null && $editingTaskCreatedBy !== auth()->id();
    @endphp

    <x-tasks.composer
        :open="$showTaskDrawer"
        model="showTaskDrawer"
        close="closeTaskDrawer"
        :editing="$editingTaskId !== null"
        :source-type-options="$showTaskDrawer ? collect($this->sourceTypeOptions)->pluck('label', 'value')->all() : []"
        :source-options="$showTaskDrawer ? collect($this->sourceOptions)->pluck('name', 'id')->all() : []"
        :status-locked="$isStatusLocked"
        :due-date-locked="$isDueDateLocked"
        :proof-locked="$isDueDateLocked"
        :due-flag="$isMissedDraft ? 'missed' : ($isOverdueDraft ? 'overdue' : null)"
        test-prefix="task-drawer"
        save-test="save-task-button"
    />
