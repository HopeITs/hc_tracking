<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

/**
 * Move a task to another due date, or clear it back to unscheduled. Only the
 * task's creator moves its due date.
 */
class RescheduleTask
{
    /**
     * @return bool|null null when the date is malformed or the task is gone; false when the actor is not the creator
     */
    public function handle(int $taskId, string $date): ?bool
    {
        if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }

        $task = Task::query()->find($taskId);

        if ($task === null) {
            return null;
        }

        Gate::authorize('update', $task);

        if ($task->created_by !== auth()->id()) {
            return false;
        }

        $task->due_date = $date === '' ? null : CarbonImmutable::parse($date);
        $task->save();

        return true;
    }
}
