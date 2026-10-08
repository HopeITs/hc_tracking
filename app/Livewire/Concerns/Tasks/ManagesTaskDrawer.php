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
use App\Models\Milestone;
use App\Models\Task;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * The tasks page's task drawer and delete confirmation, and the quick row
 * actions (complete, status, priority, assignee). Saving and rescheduling
 * come from SavesTaskForm; this trait answers its taskFormSaved() hook by
 * closing the drawer and returning the list to page one.
 *
 * Used by tasks/index only. The page uses WithPagination (resetPage); do not
 * `use SavesTaskForm` on the page as well.
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

    public function openCreateDrawer(?string $dueDate = null, ?string $status = null, ?string $milestoneId = null): void
    {
        Gate::authorize('create', Task::class);

        $this->resetTaskForm();

        if ($dueDate !== null) {
            $this->dueDate = $dueDate;
        }

        if ($status !== null && TaskStatus::tryFrom($status) !== null) {
            $this->taskStatus = $status;
        }

        if ($milestoneId !== null && Milestone::query()->whereKey((int) $milestoneId)->exists()) {
            $this->taskSourceType = 'milestone';
            $this->taskSourceId = $milestoneId;
        }

        $this->showTaskDrawer = true;
    }

    public function openEditDrawer(int $taskId): void
    {
        $task = Task::query()
            ->select(['id', 'parent_task_id', 'source_type', 'source_id', 'title', 'description', 'status', 'priority', 'start_date', 'due_date', 'completed_date', 'progress_percentage', 'created_by'])
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

        if (! app(CompleteTask::class)->handle($task)) {
            Flux::toast(variant: 'danger', text: __('tasks.completed_tasks_cannot_change_status'));

            return;
        }

        Flux::toast(variant: 'success', text: __('tasks.task_marked_done'));
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

        if (! app(ChangeTaskStatus::class)->handle($task, $newStatus)) {
            Flux::toast(variant: 'danger', text: __('tasks.completed_tasks_cannot_change_status'));

            return;
        }

        Flux::toast(variant: 'success', text: __('tasks.task_status_changed', ['status' => $newStatus->label()]));
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

        Flux::toast(variant: 'success', text: __('tasks.task_priority_changed', ['priority' => $newPriority->label()]));
    }

    /**
     * Adds or removes one assignee from a task's list, for the inline
     * multi-select popover on the list row — the drawer's own checkbox list
     * covers the same ground for a full edit, this is the quick path. A
     * task must always keep at least one assignee, matching saveTask()'s
     * own validation, so the last one can't be toggled off here either.
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
            if ($task->taskAssignees()->count() <= 1) {
                Flux::toast(variant: 'danger', text: __('tasks.assign_this_task_to_someone'));

                return;
            }

            $existing->delete();

            Flux::toast(variant: 'success', text: __('tasks.assignee_removed'));

            return;
        }

        $task->taskAssignees()->create(['employee_id' => $employeeId]);

        Flux::toast(variant: 'success', text: __('tasks.assignee_added'));
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
        $this->assignedTo = [];
        $this->taskSourceType = '';
        $this->taskSourceId = '';
    }
}
