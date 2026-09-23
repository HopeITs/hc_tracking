<?php

declare(strict_types=1);

namespace App\Notifications\Recipients;

use App\Enums\NotificationPriority;
use App\Models\Activity;
use App\Models\Project;

/**
 * Projects: the owner is direct; Managers watch; approved / closed is ★,
 * and a deleted project is critical.
 */
final class ProjectRecipients extends ModuleRecipients
{
    public function directEmployeeIds(Activity $activity): array
    {
        return $this->uniqueIds([
            Project::ownerEmployeeIdFor($activity->subject_id),
            ...$this->idsFromProperty($activity, 'employee_ids'),
            $this->changedFrom($activity, 'user_id'),
        ]);
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
            $activity->event === 'project.deleted' => NotificationPriority::Critical,
            $this->isKeyEvent($activity) => NotificationPriority::Important,
            default => NotificationPriority::Info,
        };
    }
}
