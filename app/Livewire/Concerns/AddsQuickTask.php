<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Actions\Tasks\CreateTask;
use App\Actions\Tasks\MoveTask;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\HrEmployee;
use App\Models\Task;
use Flux\Flux;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;

/**
 * The "Add task" dialog on the program, project, proposal and milestone
 * pages: a task raised straight against the record the page shows.
 *
 * The page renders x-tasks.add-task-modal, names its record through
 * quickTaskSource() and forgets its memoised task list in quickTaskCreated().
 *
 * When that record sits inside a program, the dialog also shows a "Goes to"
 * field listing every place in the program (the program itself, its
 * projects, their milestones), pre-filled with quickTaskTarget(), so it is
 * always visible where the new task will land.
 */
trait AddsQuickTask
{
    public bool $showAddTaskModal = false;

    public string $newTaskTitle = '';

    public string $newTaskDescription = '';

    public string $newTaskStatus = 'todo';

    public string $newTaskPriority = 'medium';

    public string $newTaskDueDate = '';

    /** @var array<int, string> */
    public array $newTaskAssignedTo = [];

    /** Where the new task goes: "program", "project:ID" or "milestone:ID". */
    public string $newTaskTarget = '';

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

    /**
     * The place "Goes to" starts on: the page's own record unless the page
     * knows better (the program page picks the project being viewed).
     */
    protected function quickTaskTarget(): string
    {
        $source = $this->quickTaskSource();

        return app(MoveTask::class)->targetFor($source['type'], $source['id']);
    }

    /**
     * Every place in the page record's program, or null when it is not in one
     * (then the task simply goes to the page's record and the field is hidden).
     *
     * @return array{program: array{id: int, name: string}, projects: list<array{id: int, name: string, milestones: list<array{id: int, name: string}>}>}|null
     */
    #[Computed]
    public function quickTaskPlaces(): ?array
    {
        $source = $this->quickTaskSource();
        $programId = app(MoveTask::class)->programIdOfSource($source['type'], $source['id']);

        return $programId === null ? null : app(MoveTask::class)->placesInProgram($programId);
    }

    /**
     * The page's own record as the dialog's "Related to" row shows it when
     * there is no "Goes to" choice: its type label and its name.
     *
     * @return array{type: string, name: string}
     */
    #[Computed]
    public function quickTaskSourceLabel(): array
    {
        $source = $this->quickTaskSource();
        $modelClass = Relation::getMorphedModel($source['type']);

        return [
            'type' => __('tasks.source_type_'.$source['type']),
            'name' => $modelClass === null ? '' : (string) $modelClass::query()->whereKey($source['id'])->value('name'),
        ];
    }

    public function openAddTaskModal(): void
    {
        Gate::authorize('create', Task::class);

        $this->resetValidation();
        $this->newTaskTitle = '';
        $this->newTaskDescription = '';
        $this->newTaskStatus = TaskStatus::Todo->value;
        $this->newTaskPriority = TaskPriority::Medium->value;
        $this->newTaskDueDate = '';
        $this->newTaskAssignedTo = [];
        $this->newTaskTarget = $this->quickTaskTarget();
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
            'newTaskDescription' => ['nullable', 'string', 'max:5000'],
            'newTaskStatus' => ['required', new Enum(TaskStatus::class)],
            'newTaskPriority' => ['required', new Enum(TaskPriority::class)],
            'newTaskDueDate' => ['nullable', 'date'],
            'newTaskAssignedTo' => ['array'],
            'newTaskAssignedTo.*' => ['integer', Rule::exists(HrEmployee::class, 'id')],
        ], [
            'newTaskTitle.required' => __('tasks.enter_a_task_title'),
            'newTaskAssignedTo.*.exists' => __('tasks.the_selected_assignee_is_no_longer_available'),
        ]);

        $source = $this->quickTaskSource();
        $places = $this->quickTaskPlaces;

        if ($places !== null) {
            $picked = app(MoveTask::class)->resolveTarget($places['program']['id'], $this->newTaskTarget);

            if ($picked === null) {
                $this->addError('newTaskTarget', __('tasks.goes_to_pick'));

                return;
            }

            $source = $picked;
        }

        app(CreateTask::class)->handle($source['type'], $source['id'], $validated, (int) auth()->id());

        $this->showAddTaskModal = false;
        $this->quickTaskCreated();

        Flux::toast(variant: 'success', text: __('tasks.task_created'));
    }
}
