<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\MealCoverageScope;
use App\Models\HrEmployee;
use App\Models\MealCoverageAssignment;
use App\Models\Project;
use App\Notifications\Alerts\NotificationSettings;
use Illuminate\Support\Collection;

/**
 * Who validates a project's monthly reports (contracts §12.3, D-12/D-12a).
 * Every answer is an HR employee id; map it to a user with
 * EmployeeRecipients::usersFor().
 */
final class MealCoverage
{
    /**
     * The active project officer, else the active officer of the project's
     * governorate (its center's city, falling back to projects.city_id), else
     * the active MEAL Manager slot holder, else null (callers then use the
     * MEAL role holders).
     */
    public static function responsibleFor(Project $project): ?int
    {
        $cityId = self::cityIdOf($project);

        $rows = MealCoverageAssignment::query()
            ->select(['id', 'scope_type', 'city_id', 'project_id', 'employee_id'])
            ->where(fn ($query) => $query
                ->where('project_id', $project->getKey())
                ->when($cityId !== null, fn ($query) => $query->orWhere('city_id', $cityId)))
            ->get();

        $candidates = array_values(array_filter([
            $rows->firstWhere('scope_type', MealCoverageScope::Project)?->employee_id,
            $rows->firstWhere('scope_type', MealCoverageScope::City)?->employee_id,
            self::mealManagerEmployeeId(),
        ], fn (?int $employeeId): bool => $employeeId !== null));

        $activeIds = self::activeEmployeeIds($candidates);

        foreach ($candidates as $employeeId) {
            if (in_array($employeeId, $activeIds, true)) {
                return $employeeId;
            }
        }

        return null;
    }

    /**
     * The projects assigned to the employee directly, plus the projects in
     * the governorates they cover, excluding projects assigned to someone else.
     *
     * @return list<int>
     */
    public static function projectsCoveredBy(int $employeeId): array
    {
        /** @var Collection<int, MealCoverageAssignment> $rows */
        $rows = MealCoverageAssignment::query()->get(['id', 'scope_type', 'city_id', 'project_id', 'employee_id']);
        $projectRows = $rows->where('scope_type', MealCoverageScope::Project);

        $directIds = $projectRows->where('employee_id', $employeeId)->pluck('project_id')->map(fn (int $id): int => $id)->all();
        $takenIds = $projectRows->where('employee_id', '!=', $employeeId)->pluck('project_id')->map(fn (int $id): int => $id)->all();
        $cityIds = $rows->where('scope_type', MealCoverageScope::City)->where('employee_id', $employeeId)->pluck('city_id')->map(fn (int $id): int => $id)->all();

        $inheritedIds = $cityIds === [] ? [] : Project::query()
            ->select(['id', 'city_id', 'center_id'])
            ->where(fn ($query) => $query->whereIn('city_id', $cityIds)->orWhereNotNull('center_id'))
            ->get()
            ->filter(fn (Project $project): bool => in_array(self::cityIdOf($project), $cityIds, true)
                && ! in_array($project->id, $takenIds, true))
            ->pluck('id')
            ->all();

        $ids = array_values(array_unique([...$directIds, ...$inheritedIds]));
        sort($ids);

        return $ids;
    }

    /**
     * The project's governorate: its center's city, else its own city_id.
     */
    public static function cityIdOf(Project $project): ?int
    {
        $centerCityId = $project->center()?->city_id;

        if ($centerCityId !== null) {
            return (int) $centerCityId;
        }

        $cityId = array_key_exists('city_id', $project->getAttributes())
            ? $project->getAttribute('city_id')
            : Project::query()->whereKey($project->getKey())->value('city_id');

        return $cityId === null ? null : (int) $cityId;
    }

    /**
     * The officer assigned to a governorate, active or not.
     */
    public static function officerOfCity(int $cityId): ?int
    {
        $employeeId = MealCoverageAssignment::query()->where('city_id', $cityId)->value('employee_id');

        return $employeeId === null ? null : (int) $employeeId;
    }

    /**
     * The MEAL Manager slot holder (contracts §9): the HR position in
     * `proposal_default_reviewers.meal_manager`, resolved to its current
     * holder. Null when the slot is unset or vacant.
     */
    public static function mealManagerEmployeeId(): ?int
    {
        // TODO T07: return Slots::holderOf('meal_manager');
        $slots = app(NotificationSettings::class)->get('proposal_default_reviewers');
        $positionId = is_array($slots) ? ($slots['meal_manager'] ?? null) : null;

        return $positionId === null ? null : EmployeeProfile::holderOfPosition((int) $positionId);
    }

    /**
     * Which of the given employees are active in HR (one HR query).
     *
     * @param  list<int>  $employeeIds
     * @return list<int>
     */
    public static function activeEmployeeIds(array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        return array_values(HrEmployee::query()
            ->whereKey(array_values(array_unique($employeeIds)))
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all());
    }
}
