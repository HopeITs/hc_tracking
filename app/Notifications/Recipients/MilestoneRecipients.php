<?php

declare(strict_types=1);

namespace App\Notifications\Recipients;

use App\Models\Activity;
use App\Models\Milestone;
use App\Models\Project;

/**
 * Milestones: the responsible person and the project owner are direct;
 * Managers watch; postponed / cancelled is ★.
 */
final class MilestoneRecipients extends ModuleRecipients
{
    public function directEmployeeIds(Activity $activity): array
    {
        $milestone = Milestone::query()->select(['id', 'project_id', 'responsible_user_id'])->find($activity->subject_id);

        $ids = $milestone === null
            ? $this->idsFromProperty($activity, 'employee_ids')
            : [$milestone->responsible_user_id, Project::ownerEmployeeIdFor($milestone->project_id)];

        return $this->uniqueIds([...$ids, $this->changedFrom($activity, 'responsible_user_id')]);
    }

    public function assignedEmployeeIds(Activity $activity): array
    {
        return $this->uniqueIds([match ($activity->event) {
            'milestone.responsible_changed' => $this->changedTo($activity, 'responsible_user_id'),
            'milestone.created' => Milestone::query()->whereKey($activity->subject_id)->value('responsible_user_id'),
            default => null,
        }]);
    }

    public function watcherRoles(Activity $activity): array
    {
        return ['Managers'];
    }

    public function isKeyEvent(Activity $activity): bool
    {
        return $activity->event === 'milestone.status_changed'
            && in_array($this->changedTo($activity, 'status'), ['postponed', 'cancelled'], true);
    }
}
