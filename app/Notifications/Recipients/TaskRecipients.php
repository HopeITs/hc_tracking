<?php

declare(strict_types=1);

namespace App\Notifications\Recipients;

use App\Models\Activity;
use App\Models\HrDepartment;
use App\Models\Milestone;
use App\Models\Proposal;
use App\Models\Task;
use App\Models\TaskAssignee;
use App\Support\EmployeeProfile;

/**
 * Tasks: assignees and creator are direct; no role watchers.
 *
 * A proposal plan's department task (T31, contracts §15.2) reaches its
 * department: `department_task.created` tells the department's people,
 * `.returned` its assignees and department, `.delivered` only its requester
 * (the creator). A department gone from HR hands its part to the proposal's
 * owners.
 */
final class TaskRecipients extends ModuleRecipients
{
    public function directEmployeeIds(Activity $activity): array
    {
        $assignees = [
            ...TaskAssignee::query()->where('task_id', $activity->subject_id)->pluck('employee_id')->all(),
            ...$this->idsFromProperty($activity, 'employee_ids'),
            ...$this->idsFromProperty($activity, 'employee_id'),
        ];

        return $this->uniqueIds(match ($activity->event) {
            'department_task.created' => [...$assignees, ...$this->departmentEmployeeIds($activity)],
            'department_task.returned' => [...$assignees, ...$this->departmentEmployeeIds($activity)],
            'department_task.delivered' => [],
            default => $assignees,
        });
    }

    public function directUserIds(Activity $activity): array
    {
        $creator = $activity->properties['creator_id']
            ?? Task::query()->withoutGlobalScopes()->whereKey($activity->subject_id)->value('created_by');

        return $this->uniqueIds([$creator]);
    }

    public function assignedEmployeeIds(Activity $activity): array
    {
        return $activity->event === 'task.assigned' ? $this->idsFromProperty($activity, 'employee_id') : [];
    }

    /**
     * The people of the task's HR department, or the proposal's owners when
     * the department no longer exists in HR.
     *
     * @return list<int>
     */
    private function departmentEmployeeIds(Activity $activity): array
    {
        $task = Task::query()->withoutGlobalScopes()->whereKey($activity->subject_id)->first(['id', 'department_id', 'source_type', 'source_id']);

        if ($task === null || $task->department_id === null) {
            return [];
        }

        if (HrDepartment::nameOf((int) $task->department_id) !== '') {
            return EmployeeProfile::employeeIdsInDepartment((int) $task->department_id);
        }

        $proposalId = match ($task->source_type) {
            'proposal' => (int) $task->source_id,
            'milestone' => (int) Milestone::query()->whereKey($task->source_id)->value('proposal_id'),
            default => 0,
        };

        return Proposal::ownerEmployeeIdsFor($proposalId === 0 ? [] : [$proposalId])[$proposalId] ?? [];
    }
}
