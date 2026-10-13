<?php

declare(strict_types=1);

namespace App\Notifications\Recipients;

use App\Models\Activity;

/**
 * MEAL coverage (the cities module): the new officer is assigned, the
 * previous officer and, for a project, the governorate officer it overrides
 * are told (contracts §15.2). Oversight comes from the event catalog (T21).
 */
final class MealCoverageRecipients extends ModuleRecipients
{
    public function directEmployeeIds(Activity $activity): array
    {
        return $this->uniqueIds([
            $activity->properties['employee_id'] ?? null,
            $this->changedFrom($activity, 'employee_id'),
            $activity->properties['governorate_officer_id'] ?? null,
        ]);
    }

    public function assignedEmployeeIds(Activity $activity): array
    {
        return $this->uniqueIds([$activity->properties['employee_id'] ?? null]);
    }
}
