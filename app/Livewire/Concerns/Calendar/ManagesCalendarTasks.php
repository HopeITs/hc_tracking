<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Calendar;

use App\Actions\Tasks\DeleteTask;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Livewire\Concerns\SavesTaskForm;
use App\Models\Donor;
use App\Models\DonorReport;
use App\Models\HrDepartment;
use App\Models\Milestone;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Task;
use App\Models\TransfersTracking;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * The task dialog on calendar/index: open it blank or for a task, pick the
 * source the task is raised against, and delete a task. Saving and
 * rescheduling come from SavesTaskForm; this trait answers its
 * taskFormSaved() and taskRescheduled() hooks.
 *
 * Used by calendar/index only. defaultResponsibleEmployeeId() comes from
 * ScopesCalendarPeople; the #[Computed] calendar() it refreshes from
 * BuildsCalendarGrid.
 *
 * @property-read array{month: CarbonImmutable, weeks: ?array<int, array<int, CarbonImmutable>>, days: ?array<int, CarbonImmutable>, milestonesByDay: \Illuminate\Support\Collection<string, Collection<int, Milestone>>, tasksByDay: \Illuminate\Support\Collection<string, Collection<int, Task>>, reportsByDay: \Illuminate\Support\Collection<string, Collection<int, Report>>, proposalsByDay: \Illuminate\Support\Collection<string, Collection<int, Proposal>>, transfersByDay: \Illuminate\Support\Collection<string, Collection<int, TransfersTracking>>, donorFollowUpsByDay: \Illuminate\Support\Collection<string, Collection<int, Donor>>, donorReportsByDay: \Illuminate\Support\Collection<string, Collection<int, DonorReport>>, projectStartsByDay: \Illuminate\Support\Collection<string, Collection<int, Project>>, projectEndsByDay: \Illuminate\Support\Collection<string, Collection<int, Project>>, unscheduledTasks: Collection<int, Task>, nowOffsetPercent: float} $calendar  #[Computed] calendar()
 */
trait ManagesCalendarTasks
{
    use SavesTaskForm;

    public bool $showTaskModal = false;

    public bool $showTaskDeleteModal = false;

    #[Locked]
    public ?int $editingTaskId = null;

    #[Locked]
    public ?int $deletingTaskId = null;

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

    public string $taskSourceType = 'proposal';

    public string $taskSourceId = '';

    /**
     * The morph keys a task can be raised against, labeled for the source
     * type dropdown.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function sourceTypeOptions(): array
    {
        return [
            'milestone' => __('tasks.source_type_milestone'),
            'proposal' => __('tasks.source_type_proposal'),
            'project' => __('tasks.source_type_project'),
            'program' => __('tasks.source_type_program'),
            'department' => __('tasks.source_type_department'),
        ];
    }

    /**
     * The pickable sources for the currently selected source type.
     *
     * @return Collection<int, Milestone>|Collection<int, Proposal>|Collection<int, Project>|Collection<int, Program>|Collection<int, HrDepartment>
     */
    #[Computed]
    public function sourceOptions(): Collection
    {
        return match ($this->taskSourceType) {
            'milestone' => Milestone::query()
                ->select(['id', 'name', 'project_id'])
                ->with('project:id,name')
                ->orderBy('name')
                ->orderBy('id')
                ->get(),
            'project' => Project::query()->select(['id', 'name'])->orderBy('name')->orderBy('id')->get(),
            'program' => Program::options(),
            'department' => HrDepartment::query()->select(['id', 'name'])->orderBy('name')->orderBy('id')->get(),
            default => Proposal::query()->select(['id', 'name'])->orderBy('name')->orderBy('id')->get(),
        };
    }

    public function updatedTaskSourceType(): void
    {
        $this->taskSourceId = '';
        unset($this->sourceOptions);
    }

    public function openCreateTaskModal(?string $date = null): void
    {
        Gate::authorize('create', Task::class);

        $this->resetTaskForm();

        if ($date !== null) {
            $this->dueDate = $date;
        }

        $defaultEmployeeId = $this->defaultResponsibleEmployeeId();
        $this->assignedTo = $defaultEmployeeId !== null ? [(string) $defaultEmployeeId] : [];
        $this->showTaskModal = true;
    }

    public function openEditTaskModal(int $taskId): void
    {
        $task = Task::query()
            ->select(['id', 'slug', 'source_type', 'source_id', 'title', 'description', 'status', 'priority', 'start_date', 'due_date', 'completed_date', 'progress_percentage', 'created_by'])
            ->with('taskAssignees:id,task_id,employee_id')
            ->findOrFail($taskId);

        // Someone who may not edit the task (an assignee moves only its
        // status) is taken to the task's own page instead of the edit form.
        if (Gate::denies('update', $task)) {
            $this->redirectRoute('tasks.show', ['task' => $task->slug], navigate: true);

            return;
        }

        $this->resetValidation();
        $this->editingTaskId = $task->id;
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
        unset($this->sourceOptions);
        $this->showTaskModal = true;
    }

    public function confirmDeleteTask(int $taskId): void
    {
        Gate::authorize('delete', Task::query()->findOrFail($taskId));

        $this->deletingTaskId = $taskId;
        $this->showTaskDeleteModal = true;
    }

    public function deleteTask(): void
    {
        if ($this->deletingTaskId === null) {
            return;
        }

        $taskId = $this->deletingTaskId;
        $deleted = app(DeleteTask::class)->handle($taskId);

        $this->deletingTaskId = null;
        $this->showTaskDeleteModal = false;

        if (! $deleted) {
            Flux::toast(variant: 'danger', text: __('tasks.this_task_still_has_subtasks_move_or_delete_them_first'));

            return;
        }

        unset($this->calendar);

        Flux::toast(variant: 'success', text: __('tasks.task_deleted'));
    }

    public function closeTaskModal(): void
    {
        $this->showTaskModal = false;
        $this->resetTaskForm();
    }

    protected function taskFormSaved(): void
    {
        $this->showTaskModal = false;
        $this->resetTaskForm();
        unset($this->calendar);
    }

    protected function taskRescheduled(): void
    {
        unset($this->calendar);
    }

    protected function resetTaskForm(): void
    {
        $this->resetValidation();
        $this->editingTaskId = null;
        $this->title = '';
        $this->description = '';
        $this->taskStatus = TaskStatus::Todo->value;
        $this->taskPriority = TaskPriority::Medium->value;
        $this->startDate = '';
        $this->dueDate = '';
        $this->completedDate = '';
        $this->progressPercentage = '0';
        $this->assignedTo = [];
        $this->taskSourceType = 'proposal';
        $this->taskSourceId = '';
        unset($this->sourceOptions);
    }
}
