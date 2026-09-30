<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Enums\TaskStatus;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

/**
 * Mark a task done today at 100% progress.
 */
class CompleteTask
{
    /**
     * @return bool false when the task is already done and nothing changed
     */
    public function handle(Task $task): bool
    {
        Gate::authorize('updateStatus', $task);

        if ($task->status === TaskStatus::Done) {
            return false;
        }

        $task->status = TaskStatus::Done;
        $task->completed_date = CarbonImmutable::today();
        $task->progress_percentage = 100;

        $task->save();

        return true;
    }
}
