<?php

declare(strict_types=1);

namespace App\Notifications\Recipients;

use App\Models\Activity;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Programs and annual plans: the manager is direct; Managers watch.
 */
final class ManagedRecipients extends ModuleRecipients
{
    public function directEmployeeIds(Activity $activity): array
    {
        return $this->uniqueIds([
            $this->currentManager($activity),
            ...$this->idsFromProperty($activity, 'employee_ids'),
            $this->changedFrom($activity, 'manager_id'),
        ]);
    }

    public function assignedEmployeeIds(Activity $activity): array
    {
        return $this->uniqueIds([match (true) {
            str_ends_with($activity->event, '.manager_changed') => $this->changedTo($activity, 'manager_id'),
            str_ends_with($activity->event, '.created') => $this->currentManager($activity),
            default => null,
        }]);
    }

    public function watcherRoles(Activity $activity): array
    {
        return ['Managers'];
    }

    private function currentManager(Activity $activity): ?int
    {
        $class = Relation::getMorphedModel($activity->subject_type);
        $managerId = $class === null ? null : $class::query()->whereKey($activity->subject_id)->value('manager_id');

        return $managerId === null ? null : (int) $managerId;
    }
}
