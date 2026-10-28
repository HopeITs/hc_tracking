<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Support\TaskWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Mark a task done today at 100% progress, when TaskWorkflow allows the
 * user to (its approver, with no open subtask, predecessor or missing
 * evidence).
 */
class CompleteTask
{
    /**
     * @return bool false when the task is already done and nothing changed
     *
     * @throws ValidationException when the workflow refuses (key "status")
     */
    public function handle(Task $task): bool
    {
        Gate::authorize('updateStatus', $task);

        if ($task->status === TaskStatus::Done) {
            return false;
        }

        $refusal = TaskWorkflow::refusal(auth()->user(), $task, TaskStatus::Done);

        if ($refusal !== null) {
            throw ValidationException::withMessages(['status' => $refusal]);
        }

        $task->status = TaskStatus::Done;
        $task->completed_date = CarbonImmutable::today();
        $task->progress_percentage = 100;

        $task->save();

        return true;
    }
}
