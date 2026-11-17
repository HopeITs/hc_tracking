<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Tasks;

use App\Actions\Tasks\ChangeTaskStatus;
use App\Actions\Tasks\CompleteTask;
use App\Actions\Tasks\DeleteTask;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Livewire\Concerns\SavesTaskForm;
use App\Models\HrEmployee;
use App\Models\Task;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;

/**
 * The tasks page's task drawer and delete confirmation, and the quick row
 * actions (complete, status, priority, assignee). Saving and rescheduling
 * come from SavesTaskForm; this trait answers its taskFormSaved() hook by
 * closing the drawer and returning the list to page one.
 *
 * Used by tasks/index only. The page uses WithPagination (resetPage); do not
 * `use SavesTaskForm` on the page as well.
 *
 * @property string $view
 */
trait ManagesTaskDrawer
{
    use SavesTaskForm;

    public bool $showTaskDrawer = false;

    #[Locked]
    public ?int $editingTaskId = null;

    #[Locked]
    public ?int $editingTaskCreatedBy = null;

    #[Locked]
    public ?int $deletingTaskId = null;

    public bool $showDeleteModal = false;

    public string $title = '';

    public string $description = '';

    public string $taskStatus = 'todo';

    public string $taskPriority = 'medium';

    public string $startDate = '';

    public string $dueDate = '';

    public string $completedDate = '';

    public string $progressPercentage = '0';

    /** Whether the task can only be marked done once a file is attached; only its creator changes it. */
    public bool $evidenceRequired = false;

    /** @var array<int, string> */
    public array $assignedTo = [];

    public string $taskSourceType = '';

    public string $taskSourceId = '';

    /**
     * Changing the drawer's source type invalidates whichever record was
     * picked under the old type, so the id select always starts empty
     * rather than silently keeping a now-meaningless numeric value.
     */
    public function updatedTaskSourceType(): void
    {
        $this->taskSourceId = '';
    }

    #[Computed]
    public function deletingTask(): ?Task
    {
        if ($this->deletingTaskId === null) {
            return null;
        }

        return Task::query()->select(['id', 'title'])->find($this->deletingTaskId);
    }

    /**
     * Opens the create drawer. "Related to" is prefilled the same way the inline row resolves it (active source
     * filter, else the viewer's department) and the viewer is the default
     * assignee, so a task can be saved after typing only a title.
     */
    public function openCreateDrawer(?string $dueDate = null, ?string $status = null): void
    {
        Gate::authorize('create', Task::class);

        $this->resetTaskForm();

        if ($dueDate !== null) {
            $this->dueDate = $dueDate;
        }

        if ($status !== null && TaskStatus::tryFrom($status) !== null) {
            $this->taskStatus = $status;
        }

        if (($source = $this->resolveInlineSource()) !== null) {
            $this->taskSourceType = $source['type'];
            $this->taskSourceId = (string) $source['id'];
        }

        if (($assigneeId = $this->resolveInlineAssigneeId()) !== null) {
            $this->assignedTo = [(string) $assigneeId];
        }

        $this->showTaskDrawer = true;
    }

    public function openEditDrawer(int $taskId): void
    {
        $task = Task::query()
            ->select(['id', 'parent_task_id', 'source_type', 'source_id', 'title', 'description', 'status', 'priority', 'start_date', 'due_date', 'completed_date', 'progress_percentage', 'evidence_required', 'created_by', 'department_id'])
            ->with('taskAssignees:id,task_id,employee_id')
            ->findOrFail($taskId);

        Gate::authorize('update', $task);

        $this->resetValidation();
        $this->editingTaskId = $task->id;
        $this->editingTaskCreatedBy = $task->created_by;
        $this->title = $task->title;
        $this->description = $task->description ?? '';
        $this->taskStatus = $task->status->value;
        $this->taskPriority = $task->priority->value;
        $this->startDate = $task->start_date?->toDateString() ?? '';
        $this->dueDate = $task->due_date?->toDateString() ?? '';
        $this->completedDate = $task->completed_date?->toDateString() ?? '';
        $this->progressPercentage = (string) $task->progress_percentage;
        $this->evidenceRequired = $task->evidence_required;
        $this->assignedTo = $task->taskAssignees->pluck('employee_id')->map(fn (int $id): string => (string) $id)->all();
        $this->taskSourceType = $task->source_type;
        $this->taskSourceId = (string) $task->source_id;
        $this->showTaskDrawer = true;
    }

    public function confirmDelete(int $taskId): void
    {
        Gate::authorize('delete', Task::query()->findOrFail($taskId));

        $this->deletingTaskId = $taskId;
        $this->showDeleteModal = true;
        unset($this->deletingTask);
    }

    public function cancelDelete(): void
    {
        $this->showDeleteModal = false;
        $this->deletingTaskId = null;
        unset($this->deletingTask);
    }

    public function deleteTask(): void
    {
        if ($this->deletingTaskId === null) {
            return;
        }

        $taskId = $this->deletingTaskId;
        $deleted = app(DeleteTask::class)->handle($taskId);

        $this->showDeleteModal = false;
        $this->deletingTaskId = null;
        unset($this->deletingTask);

        if (! $deleted) {
            Flux::toast(variant: 'danger', text: __('tasks.this_task_still_has_subtasks_move_or_delete_them_first'));

            return;
        }

        $this->resetPage();

        Flux::toast(variant: 'success', text: __('tasks.task_deleted'));
    }

    public function toggleComplete(int $taskId): void
    {
        $task = Task::query()->find($taskId);

        if ($task === null) {
            return;
        }

        try {
            $completed = app(CompleteTask::class)->handle($task);
        } catch (ValidationException $refused) {
            Flux::toast(variant: 'danger', text: $refused->validator->errors()->first());

            return;
        }

        if (! $completed) {
            Flux::toast(variant: 'danger', text: __('tasks.completed_tasks_cannot_change_status'));

            return;
        }

        $this->toastQuickSuccess(__('tasks.task_marked_done'));
    }

    public function quickSetStatus(int $taskId, string $status): void
    {
        $newStatus = TaskStatus::tryFrom($status);
        $task = Task::query()->find($taskId);

        if ($task === null) {
            // Most likely dropped after being deleted elsewhere — the board
            // re-renders from the database regardless, so the card already
            // "rolls back" to wherever the data actually says it is; this
            // just explains why the drop didn't visibly do anything.
            Flux::toast(variant: 'danger', text: __('tasks.this_task_no_longer_exists'));

            return;
        }

        if ($newStatus === null) {
            return;
        }

        try {
            $changed = app(ChangeTaskStatus::class)->handle($task, $newStatus);
        } catch (ValidationException $refused) {
            Flux::toast(variant: 'danger', text: $refused->validator->errors()->first());

            return;
        }

        if (! $changed) {
            Flux::toast(variant: 'danger', text: __('tasks.completed_tasks_cannot_change_status'));

            return;
        }

        // On the board the card moving to its new column is the confirmation.
        $this->toastQuickSuccess(__('tasks.task_status_changed', ['status' => $newStatus->label()]));
    }

    public function quickSetPriority(int $taskId, string $priority): void
    {
        $newPriority = TaskPriority::tryFrom($priority);
        $task = Task::query()->find($taskId);

        if ($newPriority === null || $task === null) {
            return;
        }

        Gate::authorize('update', $task);

        $task->priority = $newPriority;
        $task->save();

        $this->toastQuickSuccess(__('tasks.task_priority_changed', ['priority' => $newPriority->label()]));
    }

    /**
     * Adds or removes one assignee from a task's list, for the inline
     * multi-select popover on the list row — the drawer's own checkbox list
     * covers the same ground for a full edit, this is the quick path.
     * Removing the last assignee leaves the task unassigned.
     *
     * This one re-renders: the avatar "x" calls it directly and needs the row
     * to show the removal. The popover uses togglePickedAssignee() instead.
     */
    public function toggleAssignee(int $taskId, int $employeeId): void
    {
        $task = Task::query()->find($taskId);

        if ($task === null || ! HrEmployee::query()->whereKey($employeeId)->exists()) {
            return;
        }

        Gate::authorize('update', $task);

        $existing = $task->taskAssignees()->where('employee_id', $employeeId)->first();

        if ($existing !== null) {
            $existing->delete();

            $this->toastQuickSuccess(__('tasks.assignee_removed'));

            return;
        }

        $task->taskAssignees()->create(['employee_id' => $employeeId]);

        $this->toastQuickSuccess(__('tasks.assignee_added'));
    }

    /**
     * toggleAssignee() for the shared assignee popover.
     *
     * Renderless: re-rendering the whole list for every click cost ~700 ms,
     * so the popover's check marks move client-side and the list refreshes
     * once when the popover closes (see assignee-picker.js).
     */
    #[Renderless]
    public function togglePickedAssignee(int $taskId, int $employeeId): void
    {
        $this->toggleAssignee($taskId, $employeeId);
    }

    /**
     * The success toast of the quick inline actions (move, prioritise, assign,
     * reschedule, quick-add). The board is worked in bursts, and a toast per
     * action piles up over the cards, so it stays silent there. Moving a card to
     * another status (quickSetStatus) is the exception and always toasts, and
     * refusals still toast everywhere.
     */
    protected function toastQuickSuccess(string $text): void
    {
        if ($this->view !== 'board') {
            Flux::toast(variant: 'success', text: $text);
        }
    }

    protected function taskFormSaved(): void
    {
        $this->showTaskDrawer = false;
        $this->resetTaskForm();
        $this->resetPage();
    }

    public function closeTaskDrawer(): void
    {
        $this->showTaskDrawer = false;
        $this->resetTaskForm();
    }

    protected function resetTaskForm(): void
    {
        $this->resetValidation();
        $this->editingTaskId = null;
        $this->editingTaskCreatedBy = null;
        $this->title = '';
        $this->description = '';
        $this->taskStatus = TaskStatus::Todo->value;
        $this->taskPriority = TaskPriority::Medium->value;
        $this->startDate = '';
        $this->dueDate = '';
        $this->completedDate = '';
        $this->progressPercentage = '0';
        $this->evidenceRequired = false;
        $this->assignedTo = [];
        $this->taskSourceType = '';
        $this->taskSourceId = '';
    }
}
