<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\MealCoverage;

/**
 * MEAL coverage is assigned by holders of meal-coverage.manage who also hold
 * the MEAL Manager slot (contracts §8, §12.3). Everyone may read it.
 */
class MealCoverageAssignmentPolicy
{
    /**
     * Determine whether the user can assign or change MEAL officers.
     */
    public function manage(User $user): bool
    {
        if (! $user->can('meal-coverage.manage') || $user->employee_id === null) {
            return false;
        }

        return $user->employee_id === MealCoverage::mealManagerEmployeeId();
    }
}
