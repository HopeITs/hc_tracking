<?php

declare(strict_types=1);

namespace App\Notifications\Recipients;

use App\Enums\NotificationPriority;
use App\Models\Activity;
use App\Models\HrContract;
use App\Models\Program;
use App\Models\Project;

/**
 * Projects: the owner is direct; Managers watch; approved / closed is ★,
 * and a deleted project is critical. Decisions on the owner assignment go
 * straight to whoever made it and to the program manager; a rejection is
 * important for them, and reaches the top level when a program manager
 * is the one rejecting.
 */
final class ProjectRecipients extends ModuleRecipients
{
    public function directEmployeeIds(Activity $activity): array
    {
        return $this->uniqueIds([
            Project::ownerEmployeeIdFor($activity->subject_id),
            ...$this->idsFromProperty($activity, 'employee_ids'),
            $this->changedFrom($activity, 'user_id'),
            ...($this->isAssignmentDecision($activity) ? $this->programManagerEmployeeIds($activity) : []),
            ...$this->topLevelEmployeeIds($activity),
        ]);
    }

    public function directUserIds(Activity $activity): array
    {
        return $this->isAssignmentDecision($activity) ? $this->uniqueIds([$activity->properties['assigned_by'] ?? null]) : [];
    }

    public function assignedEmployeeIds(Activity $activity): array
    {
        return $this->uniqueIds([match ($activity->event) {
            'project.owner_changed' => $this->changedTo($activity, 'user_id'),
            'project.created' => Project::ownerEmployeeIdFor($activity->subject_id),
            default => null,
        }]);
    }

    public function watcherRoles(Activity $activity): array
    {
        return ['Managers'];
    }

    public function isKeyEvent(Activity $activity): bool
    {
        return $activity->event === 'project.deleted'
            || ($activity->event === 'project.status_changed' && in_array($this->changedTo($activity, 'status'), ['approved', 'closed'], true));
    }

    public function priority(Activity $activity): NotificationPriority
    {
        return match (true) {
            $activity->event === 'project.assignment_rejected' => NotificationPriority::Important,
            $activity->event === 'project.deleted' => NotificationPriority::Critical,
            $this->isKeyEvent($activity) => NotificationPriority::Important,
            default => NotificationPriority::Info,
        };
    }

    /**
     * Program managers (those named in programs.manager_id) have nobody above
     * them but the top level, so the top level hears only when one of them rejects being put in charge —
     * never about an acknowledgement or any other rejection.
     *
     * @return list<int>
     */
    private function topLevelEmployeeIds(Activity $activity): array
    {
        if ($activity->event !== 'project.assignment_rejected') {
            return [];
        }

        $rejecters = $this->idsFromProperty($activity, 'employee_ids');

        if (! Program::query()->whereIn('manager_id', $rejecters)->exists()) {
            return [];
        }

        return HrContract::topLevelEmployeeIds();
    }

    private function isAssignmentDecision(Activity $activity): bool
    {
        return in_array($activity->event, ['project.assignment_acknowledged', 'project.assignment_rejected', 'project.assignment_unblocked'], true);
    }

    /**
     * The manager of the project's program (programs.manager_id).
     *
     * @return list<int>
     */
    private function programManagerEmployeeIds(Activity $activity): array
    {
        $managerId = $activity->program_id === null ? null : Program::query()->whereKey($activity->program_id)->value('manager_id');

        return $this->uniqueIds([$managerId]);
    }
}
