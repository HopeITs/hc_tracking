<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Actions\Desk\SendNudge;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Support\TaskWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Move a task to another status, keeping its start date, completion date and
 * progress in step. A done task is closed and cannot be moved.
 *
 * Every move follows TaskWorkflow: an assignee who did not create the task
 * goes no further than Under review, which tells its creator; its approver
 * sends it back to In progress only with a note, which tells its assignees.
 */
class ChangeTaskStatus
{
    /**
     * @return bool false when the task is already done and nothing changed
     *
     * @throws ValidationException when the workflow refuses the move (key "status")
     */
    public function handle(Task $task, TaskStatus $status, ?string $note = null): bool
    {
        Gate::authorize('updateStatus', $task);

        if ($task->status === TaskStatus::Done) {
            return false;
        }

        $user = auth()->user();
        $note = SendNudge::cleanNote($note);
        $refusal = TaskWorkflow::refusal($user, $task, $status, $note);

        if ($refusal !== null) {
            throw ValidationException::withMessages(['status' => $refusal]);
        }

        $from = $task->status;
        $approves = TaskWorkflow::approves($user, $task);
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

        TaskWorkflow::announce($user, $task, $from, $approves, $note);

        return true;
    }
}
