<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Actions\Tasks\CreateTask;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\HrEmployee;
use App\Models\Task;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * The "Add task" dialog on the program, project, proposal and milestone
 * pages: a task raised straight against the record the page shows.
 *
 * The page renders x-tasks.add-task-modal, names its record through
 * quickTaskSource() and forgets its memoised task list in quickTaskCreated().
 */
trait AddsQuickTask
{
    public bool $showAddTaskModal = false;

    public string $newTaskTitle = '';

    public string $newTaskStatus = 'todo';

    public string $newTaskPriority = 'medium';

    public string $newTaskDueDate = '';

    /** @var array<int, string> */
    public array $newTaskAssignedTo = [];

    /**
     * The record a new task is raised against: its morph map key and id.
     *
     * @return array{type: string, id: int}
     */
    abstract protected function quickTaskSource(): array;

    /**
     * Forget whatever the page memoised about its tasks.
     */
    abstract protected function quickTaskCreated(): void;

    public function openAddTaskModal(): void
    {
        Gate::authorize('create', Task::class);

        $this->resetValidation();
        $this->newTaskTitle = '';
        $this->newTaskStatus = TaskStatus::Todo->value;
        $this->newTaskPriority = TaskPriority::Medium->value;
        $this->newTaskDueDate = '';
        $this->newTaskAssignedTo = [];
        $this->showAddTaskModal = true;
    }

    public function closeAddTaskModal(): void
    {
        $this->showAddTaskModal = false;
    }

    public function addTask(): void
    {
        Gate::authorize('create', Task::class);

        $this->newTaskTitle = trim($this->newTaskTitle);

        $validated = $this->validate([
            'newTaskTitle' => ['required', 'string', 'max:255'],
            'newTaskStatus' => ['required', new Enum(TaskStatus::class)],
            'newTaskPriority' => ['required', new Enum(TaskPriority::class)],
            'newTaskDueDate' => ['nullable', 'date'],
            'newTaskAssignedTo' => ['required', 'array', 'min:1'],
            'newTaskAssignedTo.*' => ['integer', Rule::exists(HrEmployee::class, 'id')],
        ], [
            'newTaskTitle.required' => __('tasks.enter_a_task_title'),
            'newTaskAssignedTo.required' => __('tasks.assign_this_task_to_someone'),
            'newTaskAssignedTo.min' => __('tasks.assign_this_task_to_someone'),
            'newTaskAssignedTo.*.exists' => __('tasks.the_selected_assignee_is_no_longer_available'),
        ]);

        $source = $this->quickTaskSource();

        app(CreateTask::class)->handle($source['type'], $source['id'], $validated, (int) auth()->id());

        $this->showAddTaskModal = false;
        $this->quickTaskCreated();

        Flux::toast(variant: 'success', text: __('tasks.task_created'));
    }
}
