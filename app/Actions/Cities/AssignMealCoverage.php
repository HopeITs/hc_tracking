<?php

declare(strict_types=1);

namespace App\Actions\Cities;

use App\Enums\MealCoverageScope;
use App\Models\HrCity;
use App\Models\HrEmployee;
use App\Models\MealCoverageAssignment;
use App\Models\Project;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Make an employee the MEAL officer of a governorate (HR city) or of one
 * project (contracts §12.3). A different employee replaces the row; the same
 * employee again is a no-op. Error keys match the cities page's modal fields.
 */
class AssignMealCoverage
{
    /**
     * @throws ValidationException when the employee is not active in HR or the city/project does not exist
     */
    public function handle(MealCoverageScope $scope, int $targetId, int $employeeId): MealCoverageAssignment
    {
        Gate::authorize('manage', MealCoverageAssignment::class);

        Validator::make(['mealEmployeeId' => $employeeId], [
            'mealEmployeeId' => [function (string $attribute, mixed $value, Closure $fail): void {
                if (! HrEmployee::query()->whereKey($value)->where('is_active', true)->exists()) {
                    $fail(__('cities.choose_an_active_employee'));
                }
            }],
        ])->validate();

        $this->ensureTargetExists($scope, $targetId);

        $column = match ($scope) {
            MealCoverageScope::City => 'city_id',
            MealCoverageScope::Project => 'project_id',
        };

        return DB::transaction(function () use ($scope, $column, $targetId, $employeeId): MealCoverageAssignment {
            $existing = MealCoverageAssignment::query()->where($column, $targetId)->lockForUpdate()->first();

            if ($existing === null) {
                try {
                    return MealCoverageAssignment::query()->create([
                        'scope_type' => $scope,
                        $column => $targetId,
                        'employee_id' => $employeeId,
                        'assigned_by' => Auth::id(),
                    ]);
                } catch (UniqueConstraintViolationException) {
                    // Another request created the row after we looked: take it over.
                    $existing = MealCoverageAssignment::query()->where($column, $targetId)->lockForUpdate()->firstOrFail();
                }
            }

            if ($existing->employee_id !== $employeeId) {
                $existing->update(['employee_id' => $employeeId, 'assigned_by' => Auth::id()]);
            }

            return $existing;
        });
    }

    /**
     * The city must exist in HR (active or not); the project must exist and be
     * visible to the user.
     */
    private function ensureTargetExists(MealCoverageScope $scope, int $targetId): void
    {
        if ($scope === MealCoverageScope::City) {
            if (! HrCity::query()->whereKey($targetId)->exists()) {
                throw ValidationException::withMessages(['mealTargetId' => __('cities.city_not_found')]);
            }

            return;
        }

        $project = Project::query()->select(['id'])->whereKey($targetId)->first();

        if ($project === null) {
            throw ValidationException::withMessages(['mealTargetId' => __('cities.project_not_found')]);
        }

        Gate::authorize('view', $project);
    }
}
