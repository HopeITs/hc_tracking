<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Enums\DeliveryState;
use App\Enums\TaskStatus;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Accept a delivered media deliverable (contracts.md §12.2; T31): delivered →
 * accepted, and the task is done today at 100%.
 *
 * One transaction: the task is re-read through the visibility scope, locked
 * and compared with the state the caller saw before anything is written. The
 * last return reason is cleared: an accepted deliverable has nothing left to
 * fix. The save records the usual `task.status_changed` activity.
 */
final class AcceptDepartmentTask
{
    /**
     * @throws ModelNotFoundException<Task> when the task is not visible to the user
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Task $task, DeliveryState $expectedState): Task
    {
        return DB::transaction(function () use ($task, $expectedState): Task {
            $locked = Task::query()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();

            Gate::authorize('review', $locked);

            if ($locked->delivery_state !== $expectedState) {
                throw ValidationException::withMessages(['delivery_state' => __('department_tasks.stale_delivery_state')]);
            }

            if ($expectedState !== DeliveryState::Delivered) {
                throw ValidationException::withMessages(['delivery_state' => __('department_tasks.not_delivered_yet')]);
            }

            $locked->delivery_state = DeliveryState::Accepted;
            $locked->accepted_at = CarbonImmutable::now();
            $locked->returned_reason = null;
            $locked->status = TaskStatus::Done;
            $locked->completed_date = CarbonImmutable::today();
            $locked->progress_percentage = 100;
            $locked->save();

            return $locked;
        });
    }
}
