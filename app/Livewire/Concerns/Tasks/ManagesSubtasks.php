<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Tasks;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\HrEmployee;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;

/**
 * The related-tasks panel on tasks/show: the task's subtasks and the add
 * subtask dialog.
 *
 * Used by tasks/show only. The page provides taskId and the #[Computed]
 * task(), which addSubtask() and openAddSubtaskModal() read.
 *
 * @property int $taskId
 * @property-read Task $task  #[Computed] task()
 * @property-read Collection<int, Task> $subtasks  #[Computed] subtasks()
 */
trait ManagesSubtasks
{
    public bool $showAddSubtaskModal = false;

    public string $newSubtaskTitle = '';

    public string $newSubtaskStatus = 'todo';

    public string $newSubtaskPriority = 'medium';

    public string $newSubtaskDueDate = '';

    /** @var array<int, string> */
    public array $newSubtaskAssignedTo = [];

    /** @return Collection<int, Task> */
    #[Computed]
    public function subtasks(): Collection
    {
        return Task::query()
            ->select(['id', 'title', 'status', 'priority', 'due_date', 'progress_percentage'])
            ->where('parent_task_id', $this->taskId)
            ->with('taskAssignees.employee:id,first_name_en,last_name_en,first_name_ar,last_name_ar')
            ->orderBy('id')
            ->get();
    }

    /**
     * A new subtask inherits its parent's source, so only its own title,
     * status, priority, due date and assignees need asking for. Assignees
     * default to the parent's own — mirrors the list page's subtaskDraft()
     * default — so the common case (same people finish the smaller step)
     * needs no extra clicks.
     */
    public function openAddSubtaskModal(): void
    {
        Gate::authorize('create', Task::class);

        $this->resetValidation();
        $this->newSubtaskTitle = '';
        $this->newSubtaskStatus = TaskStatus::Todo->value;
        $this->newSubtaskPriority = TaskPriority::Medium->value;
        $this->newSubtaskDueDate = '';
        $this->newSubtaskAssignedTo = $this->task->taskAssignees->pluck('employee_id')->map(fn (int $id): string => (string) $id)->all();
        $this->showAddSubtaskModal = true;
    }

    public function closeAddSubtaskModal(): void
    {
        $this->showAddSubtaskModal = false;
    }

    public function addSubtask(): void
    {
        // Subtasks are one level deep: judged by the column, since the parent
        // itself may be hidden from this viewer.
        if ($this->task->parent_task_id !== null) {
            return;
        }

        Gate::authorize('create', Task::class);

        $this->newSubtaskTitle = trim($this->newSubtaskTitle);

        /** @var array{newSubtaskTitle: string, newSubtaskStatus: string, newSubtaskPriority: string, newSubtaskDueDate: string|null, newSubtaskAssignedTo: array<int, string>} $validated */
        $validated = $this->validate([
            'newSubtaskTitle' => ['required', 'string', 'max:255'],
            'newSubtaskStatus' => ['required', new Enum(TaskStatus::class)],
            'newSubtaskPriority' => ['required', new Enum(TaskPriority::class)],
            'newSubtaskDueDate' => ['nullable', 'date'],
            'newSubtaskAssignedTo' => ['required', 'array', 'min:1'],
            'newSubtaskAssignedTo.*' => ['integer', Rule::exists(HrEmployee::class, 'id')],
        ], [
            'newSubtaskTitle.required' => __('tasks.enter_a_task_title'),
            'newSubtaskAssignedTo.required' => __('tasks.assign_this_task_to_someone'),
            'newSubtaskAssignedTo.min' => __('tasks.assign_this_task_to_someone'),
            'newSubtaskAssignedTo.*.exists' => __('tasks.the_selected_assignee_is_no_longer_available'),
        ]);

        $parent = $this->task;
        $statusCase = TaskStatus::from($validated['newSubtaskStatus']);
        $employeeIds = collect($validated['newSubtaskAssignedTo'])->map(fn ($id): int => (int) $id)->unique()->values();

        DB::transaction(function () use ($parent, $validated, $statusCase, $employeeIds): void {
            $subtask = Task::query()->create([
                'parent_task_id' => $parent->id,
                'source_type' => $parent->source_type,
                'source_id' => $parent->source_id,
                'title' => $validated['newSubtaskTitle'],
                'status' => $statusCase,
                'priority' => $validated['newSubtaskPriority'],
                'due_date' => $validated['newSubtaskDueDate'] === '' ? null : $validated['newSubtaskDueDate'],
                'progress_percentage' => $statusCase === TaskStatus::Done ? 100 : 0,
                'completed_date' => $statusCase === TaskStatus::Done ? CarbonImmutable::today() : null,
                'created_by' => (int) auth()->id(),
            ]);

            foreach ($employeeIds as $employeeId) {
                $subtask->taskAssignees()->create(['employee_id' => $employeeId]);
            }
        });

        $this->showAddSubtaskModal = false;
        unset($this->subtasks);

        Flux::toast(variant: 'success', text: __('tasks.subtask_added'));
    }
}
