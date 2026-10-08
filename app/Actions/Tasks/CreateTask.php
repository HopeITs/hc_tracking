<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Enums\TaskStatus;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Raise a task straight against one record (a program, project, proposal or
 * milestone) and assign it, in one transaction.
 */
class CreateTask
{
    /**
     * @param  string  $sourceType  the morph map key: 'program', 'project', 'proposal' or 'milestone'
     * @param  array{newTaskTitle: string, newTaskStatus: string, newTaskPriority: string, newTaskDueDate: string, newTaskAssignedTo: array<int, int|string>}  $validated  keyed by the add-task form's property names
     */
    public function handle(string $sourceType, int $sourceId, array $validated, int $createdBy): Task
    {
        Gate::authorize('create', Task::class);

        $statusCase = TaskStatus::from($validated['newTaskStatus']);
        $employeeIds = collect($validated['newTaskAssignedTo'])->map(fn ($id): int => (int) $id)->unique()->values();

        return DB::transaction(function () use ($sourceType, $sourceId, $validated, $statusCase, $employeeIds, $createdBy): Task {
            $task = Task::query()->create([
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'title' => $validated['newTaskTitle'],
                'status' => $statusCase,
                'priority' => $validated['newTaskPriority'],
                'due_date' => $validated['newTaskDueDate'] === '' ? null : $validated['newTaskDueDate'],
                'progress_percentage' => $statusCase === TaskStatus::Done ? 100 : 0,
                'completed_date' => $statusCase === TaskStatus::Done ? CarbonImmutable::today() : null,
                'created_by' => $createdBy,
            ]);

            foreach ($employeeIds as $employeeId) {
                $task->taskAssignees()->create(['employee_id' => $employeeId]);
            }

            return $task;
        });
    }
}
