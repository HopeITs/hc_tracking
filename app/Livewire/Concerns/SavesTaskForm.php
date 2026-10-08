<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Actions\Tasks\RescheduleTask;
use App\Actions\Tasks\SaveTask;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\HrDepartment;
use App\Models\HrEmployee;
use App\Models\Milestone;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Task;
use Closure;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Saving and rescheduling a task from the task form, shared by the tasks
 * directory drawer and the calendar's task dialog so both accept exactly the
 * same input and enforce the same rules (a done task is closed; only its
 * creator moves its due date).
 *
 * The page closes its own form in taskFormSaved() and refreshes whatever it
 * memoised in taskRescheduled().
 *
 * @property ?int $editingTaskId
 * @property string $title
 * @property string $description
 * @property string $taskStatus
 * @property string $taskPriority
 * @property string $startDate
 * @property string $dueDate
 * @property string $completedDate
 * @property string $progressPercentage
 * @property array<int, string> $assignedTo
 * @property string $taskSourceType
 * @property string $taskSourceId
 */
trait SavesTaskForm
{
    /** The model behind each task source type. */
    protected const TASK_SOURCE_MODELS = [
        'milestone' => Milestone::class,
        'proposal' => Proposal::class,
        'project' => Project::class,
        'program' => Program::class,
        'department' => HrDepartment::class,
    ];

    /** @return array<string, array<int, mixed>> */
    protected function taskFormRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'taskStatus' => ['required', new Enum(TaskStatus::class)],
            'taskPriority' => ['required', new Enum(TaskPriority::class)],
            'startDate' => ['nullable', 'date'],
            'dueDate' => ['nullable', 'date', Rule::when($this->startDate !== '', ['after_or_equal:startDate'])],
            'completedDate' => ['nullable', 'date'],
            'progressPercentage' => ['required', 'integer', 'min:0', 'max:100'],
            'assignedTo' => ['required', 'array', 'min:1'],
            'assignedTo.*' => ['integer', Rule::exists(HrEmployee::class, 'id')],
            'taskSourceType' => ['required', 'string', Rule::in(array_keys(self::TASK_SOURCE_MODELS))],
            'taskSourceId' => [
                'required',
                'integer',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $modelClass = self::TASK_SOURCE_MODELS[$this->taskSourceType] ?? null;

                    if ($modelClass !== null && ! $modelClass::query()->whereKey((int) $value)->exists()) {
                        $fail(__('tasks.the_selected_source_is_no_longer_available'));
                    }
                },
            ],
        ];
    }

    /** @return array<string, string> */
    protected function taskFormMessages(): array
    {
        return [
            'title.required' => __('tasks.enter_a_task_title'),
            'assignedTo.required' => __('tasks.assign_this_task_to_someone'),
            'assignedTo.min' => __('tasks.assign_this_task_to_someone'),
            'assignedTo.*.exists' => __('tasks.the_selected_assignee_is_no_longer_available'),
            'taskSourceType.required' => __('tasks.select_the_source_this_task_is_raised_against'),
            'taskSourceId.required' => __('tasks.select_the_source_this_task_is_raised_against'),
            'dueDate.after_or_equal' => __('tasks.the_due_date_cannot_fall_before_the_start_date'),
            'progressPercentage.min' => __('tasks.progress_cannot_be_negative'),
            'progressPercentage.max' => __('tasks.progress_cannot_exceed_100_percent'),
        ];
    }

    protected function trimTaskForm(): void
    {
        foreach (['title', 'description', 'taskStatus', 'taskPriority', 'startDate', 'dueDate', 'completedDate', 'progressPercentage', 'taskSourceType', 'taskSourceId'] as $property) {
            $this->{$property} = trim($this->{$property});
        }
    }

    /**
     * Close the page's task form and refresh what it shows after a save.
     */
    abstract protected function taskFormSaved(): void;

    /**
     * Refresh what the page memoised after a task's due date moved.
     */
    protected function taskRescheduled(): void
    {
        //
    }

    public function saveTask(): void
    {
        if ($this->editingTaskId === null) {
            Gate::authorize('create', Task::class);
        } else {
            Gate::authorize('update', Task::query()->findOrFail($this->editingTaskId));
        }

        $this->trimTaskForm();

        $validated = $this->validate($this->taskFormRules(), $this->taskFormMessages());

        $isCreate = $this->editingTaskId === null;
        $result = app(SaveTask::class)->handle(
            $isCreate ? null : Task::query()->findOrFail($this->editingTaskId),
            $validated,
            (int) auth()->id(),
        );

        if ($result['lockedBecauseDone']) {
            Flux::toast(variant: 'danger', text: __('tasks.completed_tasks_cannot_change_status'));

            return;
        }

        if ($result['dueDateKept']) {
            Flux::toast(variant: 'danger', text: __('tasks.only_the_task_creator_can_change_the_due_date'));
        }

        $this->taskFormSaved();

        Flux::toast(variant: 'success', text: $isCreate ? __('tasks.task_created') : __('tasks.task_updated'));
    }

    /**
     * Moves a task to a new due date (or clears it, back to "unscheduled")
     * when it is dropped on a day or on the unscheduled list.
     */
    public function rescheduleTask(int $taskId, string $date): void
    {
        $outcome = app(RescheduleTask::class)->handle($taskId, $date);

        if ($outcome === null) {
            return;
        }

        if ($outcome === false) {
            Flux::toast(variant: 'danger', text: __('tasks.only_the_task_creator_can_change_the_due_date'));

            return;
        }

        $this->taskRescheduled();

        Flux::toast(variant: 'success', text: __('tasks.task_rescheduled'));
    }
}
