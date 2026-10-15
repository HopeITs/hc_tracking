<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Enums\DeliveryState;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Task;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Return a delivered media deliverable with a reason (contracts.md §12.2;
 * T31): delivered → returned, and the task goes back to `in progress`.
 *
 * One transaction: the task is re-read through the visibility scope, locked
 * and compared with the state the caller saw before anything is written. The
 * move records one `department_task.returned` activity (the reason travels in
 * its changes), never a generic status change.
 */
final class ReturnDepartmentTask
{
    private const MaxReasonLength = 2000;

    /**
     * @throws ModelNotFoundException<Task> when the task is not visible to the user
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Task $task, DeliveryState $expectedState, string $reason): Task
    {
        $reason = trim($reason);

        return DB::transaction(function () use ($task, $expectedState, $reason): Task {
            $locked = Task::query()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();

            Gate::authorize('review', $locked);

            if ($locked->delivery_state !== $expectedState) {
                throw ValidationException::withMessages(['delivery_state' => __('department_tasks.stale_delivery_state')]);
            }

            if ($expectedState !== DeliveryState::Delivered) {
                throw ValidationException::withMessages(['delivery_state' => __('department_tasks.not_delivered_yet')]);
            }

            Validator::make(['reason' => $reason], [
                'reason' => ['required', 'string', 'max:'.self::MaxReasonLength],
            ], [
                'reason.required' => __('department_tasks.return_reason_required'),
            ])->validate();

            $fromStatus = $locked->status;
            $fromReason = $locked->returned_reason;

            Activity::withoutRecording(function () use ($locked, $reason): void {
                $locked->delivery_state = DeliveryState::Returned;
                $locked->status = TaskStatus::InProgress;
                $locked->returned_reason = $reason;
                $locked->save();
            });

            $locked->recordActivity('department_task.returned', [
                'delivery_state' => [DeliveryState::Delivered->value, DeliveryState::Returned->value],
                'status' => [$fromStatus->value, TaskStatus::InProgress->value],
                'returned_reason' => [$fromReason, $reason],
            ]);

            return $locked;
        });
    }
}
