<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Enums\TaskStatus;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Create or edit a task from the task form (tasks directory drawer, calendar
 * dialog). A done task is closed and is not changed; only the task's creator
 * moves its due date, so anyone else's due-date change is dropped.
 */
class SaveTask
{
    /**
     * @param  array{title: string, description: string, taskStatus: string, taskPriority: string, startDate: string, dueDate: string, completedDate: string, progressPercentage: string, assignedTo: array<int, string>, taskSourceType: string, taskSourceId: string}  $validated  keyed by the task form's property names
     * @return array{task: ?Task, lockedBecauseDone: bool, dueDateKept: bool}
     */
    public function handle(?Task $existing, array $validated, int $actorId): array
    {
        $isCreate = $existing === null;

        if ($isCreate) {
            Gate::authorize('create', Task::class);
        } else {
            Gate::authorize('update', $existing);
        }

        if (! $isCreate && $existing->status === TaskStatus::Done) {
            return ['task' => null, 'lockedBecauseDone' => true, 'dueDateKept' => false];
        }

        $task = $existing ?? new Task;
        $dueDate = $validated['dueDate'] === '' ? null : $validated['dueDate'];
        $dueDateKept = false;

        if (! $isCreate && $task->created_by !== $actorId && $dueDate !== $task->due_date?->toDateString()) {
            $dueDate = $task->due_date?->toDateString();
            $dueDateKept = true;
        }

        $attributes = [
            'title' => $validated['title'],
            'description' => $validated['description'] === '' ? null : $validated['description'],
            'status' => $validated['taskStatus'],
            'priority' => $validated['taskPriority'],
            'start_date' => $validated['startDate'] === '' ? null : $validated['startDate'],
            'due_date' => $dueDate,
            'completed_date' => $validated['completedDate'] === '' ? null : $validated['completedDate'],
            'progress_percentage' => (int) $validated['progressPercentage'],
            'source_type' => $validated['taskSourceType'],
            'source_id' => (int) $validated['taskSourceId'],
        ];

        if ($isCreate) {
            $attributes['created_by'] = $actorId;
        }

        DB::transaction(function () use ($task, $attributes, $validated): void {
            $task->fill($attributes)->save();

            $employeeIds = collect($validated['assignedTo'])->map(fn ($id): int => (int) $id)->unique()->values();

            $task->taskAssignees()->whereNotIn('employee_id', $employeeIds)->delete();
            $existingIds = $task->taskAssignees()->pluck('employee_id');

            $employeeIds->diff($existingIds)->each(fn (int $employeeId) => $task->taskAssignees()->create([
                'employee_id' => $employeeId,
            ]));
        });

        return ['task' => $task, 'lockedBecauseDone' => false, 'dueDateKept' => $dueDateKept];
    }
}
