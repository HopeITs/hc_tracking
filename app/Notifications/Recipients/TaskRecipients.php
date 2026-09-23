<?php

declare(strict_types=1);

namespace App\Notifications\Recipients;

use App\Models\Activity;
use App\Models\Task;
use App\Models\TaskAssignee;

/**
 * Tasks: assignees and creator are direct; no role watchers.
 */
final class TaskRecipients extends ModuleRecipients
{
    public function directEmployeeIds(Activity $activity): array
    {
        return $this->uniqueIds([
            ...TaskAssignee::query()->where('task_id', $activity->subject_id)->pluck('employee_id')->all(),
            ...$this->idsFromProperty($activity, 'employee_ids'),
            ...$this->idsFromProperty($activity, 'employee_id'),
        ]);
    }

    public function directUserIds(Activity $activity): array
    {
        $creator = $activity->properties['creator_id']
            ?? Task::query()->whereKey($activity->subject_id)->value('created_by');

        return $this->uniqueIds([$creator]);
    }

    public function assignedEmployeeIds(Activity $activity): array
    {
        return $activity->event === 'task.assigned' ? $this->idsFromProperty($activity, 'employee_id') : [];
    }
}
