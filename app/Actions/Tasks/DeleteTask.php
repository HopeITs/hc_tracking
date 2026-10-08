<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Models\Scopes\TaskVisibilityScope;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Delete a task that has no subtasks, holding a row lock so a subtask added
 * at the same moment cannot be orphaned.
 */
class DeleteTask
{
    /**
     * @return bool false when the task is gone or still has subtasks
     */
    public function handle(int $taskId): bool
    {
        return DB::transaction(function () use ($taskId): bool {
            $task = Task::query()->lockForUpdate()->find($taskId);

            if ($task === null || $task->subtasks()->withoutGlobalScope(TaskVisibilityScope::class)->exists()) {
                return false;
            }

            Gate::authorize('delete', $task);

            return (bool) $task->delete();
        });
    }
}
