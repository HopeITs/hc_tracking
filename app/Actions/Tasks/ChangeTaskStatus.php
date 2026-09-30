<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Enums\TaskStatus;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

/**
 * Move a task to another status, keeping its start date, completion date and
 * progress in step. A done task is closed and cannot be moved.
 */
class ChangeTaskStatus
{
    /**
     * @return bool false when the task is already done and nothing changed
     */
    public function handle(Task $task, TaskStatus $status): bool
    {
        Gate::authorize('updateStatus', $task);

        if ($task->status === TaskStatus::Done) {
            return false;
        }

        $task->status = $status;

        if ($status === TaskStatus::InProgress) {
            $task->start_date ??= CarbonImmutable::today();
        }

        if ($status === TaskStatus::Done) {
            $task->completed_date ??= CarbonImmutable::today();
            $task->progress_percentage = 100;
        } elseif ($task->completed_date !== null) {
            $task->completed_date = null;
        }

        $task->save();

        return true;
    }
}
