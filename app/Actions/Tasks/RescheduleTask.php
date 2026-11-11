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
     * @return bool|null null when the date is malformed or the task is gone; false when the actor is not the creator or may not edit it
     */
    public function handle(int $taskId, string $date): ?bool
    {
        $dueDate = null;

        if ($date !== '') {
            // Carbon rolls an impossible day over (2026-02-31 becomes March 3)
            // instead of failing, so the parsed day must format back to the input.
            $dueDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $date) : null;

            if (! $dueDate instanceof CarbonImmutable || $dueDate->format('Y-m-d') !== $date) {
                return null;
            }
        }

        $task = Task::query()->find($taskId);

        if ($task === null) {
            return null;
        }

        // Refused with false (a message), never a 403 page: a calendar drop is
        // a casual gesture, and the card simply returns to its day on refusal.
        if ($task->created_by !== auth()->id() || Gate::denies('update', $task)) {
            return false;
        }

        $task->due_date = $dueDate;
        $task->save();

        return true;
    }
}
