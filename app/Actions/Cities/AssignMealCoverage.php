<?php

declare(strict_types=1);

namespace App\Actions\Cities;

use App\Enums\MealCoverageScope;
use App\Models\HrCenter;
use App\Models\HrCity;
use App\Models\HrEmployee;
use App\Models\MealCoverageAssignment;
use App\Models\Program;
use App\Support\Reporting\ReportPlace;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Make an employee the MEAL officer of a governorate (HR city) or of one
 * project (contracts Ã‚Â§12.3). A different employee replaces the row; the same
 * employee again is a no-op. Error keys match the cities page's modal fields.
 */
class AssignMealCoverage
{
    /**
     * @throws ValidationException when the employee is not active in HR or the target does not exist
     */
    public function handle(MealCoverageScope $scope, array|int $target, int $employeeId): MealCoverageAssignment
    {
        Gate::authorize('manage', MealCoverageAssignment::class);

        Validator::make(['mealEmployeeId' => $employeeId], [
            'mealEmployeeId' => [function (string $attribute, mixed $value, Closure $fail): void {
                if (! HrEmployee::query()->whereKey($value)->where('is_active', true)->exists()) {
                    $fail(__('cities.choose_an_active_employee'));
                }
            }],
        ])->validate();

        $target = is_int($target) ? ['city_id' => $target] : $target;
        $city = (int) ($target['city_id'] ?? 0);
        $conditions = ['scope_type' => $scope->value, 'city_id' => $city];
        $attributes = $conditions + ['scope_key' => 'city:'.$city];
        if (! HrCity::query()->whereKey($city)->exists()) {
            throw ValidationException::withMessages(['mealTargetId' => __('cities.city_not_found')]);
        }

        if ($scope === MealCoverageScope::Place) {
            $program = (int) ($target['program_id'] ?? 0);
            $center = isset($target['center_id']) ? (int) $target['center_id'] : null;
            $wide = (bool) ($target['city_wide'] ?? false);
            if (! Program::query()->whereKey($program)->exists() || $wide !== ($center === null)
                || ($center !== null && ! HrCenter::query()->whereKey($center)->where('city_id', $city)->exists())) {
                throw ValidationException::withMessages(['mealTargetId' => __('cities.meal_place_invalid')]);
            }
            $place = new ReportPlace($city, $program, $center, $wide);
            $attributes = [...$conditions, 'program_id' => $program, 'center_id' => $center, 'city_wide' => $wide, 'scope_key' => 'place:'.$place->key()];
            $conditions = ['scope_key' => $attributes['scope_key']];
        } elseif ($scope !== MealCoverageScope::City) {
            throw ValidationException::withMessages(['mealTargetId' => __('cities.meal_place_invalid')]);
        }

        return DB::transaction(function () use ($conditions, $attributes, $employeeId): MealCoverageAssignment {
            $existing = MealCoverageAssignment::query()->where($conditions)->lockForUpdate()->first();

            if ($existing === null) {
                try {
                    return MealCoverageAssignment::query()->create([
                        ...$attributes,
                        'employee_id' => $employeeId,
                        'assigned_by' => Auth::id(),
                    ]);
                } catch (UniqueConstraintViolationException) {
                    // Another request created the row after we looked: take it over.
                    $existing = MealCoverageAssignment::query()->where($conditions)->lockForUpdate()->firstOrFail();
                }
            }

            if ($existing->employee_id !== $employeeId) {
                $existing->update(['employee_id' => $employeeId, 'assigned_by' => Auth::id(), 'scope_key' => $attributes['scope_key']]);
            }

            return $existing;
        });
    }
}
