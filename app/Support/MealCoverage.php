<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\MealCoverageScope;
use App\Models\HrEmployee;
use App\Models\MealCoverageAssignment;
use App\Models\Project;
use App\Models\ProposalLocation;
use App\Support\Locations\ProjectSuccessor;
use App\Support\Reporting\ReportPlace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Who validates a project's monthly reports (contracts Â§12.3, D-12/D-12a).
 * Every answer is an HR employee id; map it to a user with
 * EmployeeRecipients::usersFor().
 */
final class MealCoverage
{
    public static function responsibleForPlace(ReportPlace $place): ?int
    {
        $placeOfficer = $place->isUnset() ? null : MealCoverageAssignment::query()
            ->where('scope_key', 'place:'.$place->key())
            ->value('employee_id');
        $candidates = array_values(array_filter([
            $placeOfficer === null ? null : (int) $placeOfficer,
            self::officerOfCity($place->cityId),
            self::mealManagerEmployeeId(),
        ], fn (?int $id): bool => $id !== null));

        $active = self::activeEmployeeIds($candidates);

        foreach ($candidates as $id) {
            if (in_array($id, $active, true)) {
                return $id;
            }
        }

        return null;
    }

    /** @return list<int> */
    public static function responsibleForLocation(ProposalLocation $row): array
    {
        $row->loadMissing('centers');
        $centers = $row->city_wide || $row->centers->isEmpty()
            ? [null]
            : $row->centers->pluck('center_id')->all();
        $officers = [];

        foreach ($centers as $center) {
            $officer = self::responsibleForPlace(new ReportPlace(
                $row->city_id,
                (int) $row->program_id,
                $center === null ? null : (int) $center,
                $row->city_wide,
            ));
            if ($officer !== null) {
                $officers[$officer] = $officer;
            }
        }

        return array_values($officers);
    }

    /**
     * Resolve all place officers for callers that still carry a legacy project
     * identifier during the annual-plan and notification transition.
     *
     * @return list<int>
     */
    public static function officersForLegacyProject(int $projectId, ?int $proposalId = null): array
    {
        return app(ProjectSuccessor::class)->rowsFor($projectId)
            ->filter(fn (ProposalLocation $row): bool => $proposalId === null || $row->proposal_id === $proposalId)
            ->loadMissing('centers')
            ->flatMap(self::responsibleForLocation(...))
            ->unique()->values()->all();
    }

    public static function covers(int $employeeId, ReportPlace $place): bool
    {
        return self::responsibleForPlace($place) === $employeeId;
    }

    /**
     * Select direct place assignments and governorate coverage only where
     * there is no explicit place assignment.
     */
    public static function scopeCoveredBy(Builder $query, int $employeeId, bool $includeUnassigned = false): Builder
    {
        self::constrainPlaceColumns($query, $employeeId, $query->getModel()->qualifyColumn(...), $includeUnassigned);

        return $query;
    }

    /** @param callable(string): string $column */
    public static function constrainPlaceColumns(Builder|QueryBuilder $query, int $employeeId, callable $column, bool $includeUnassigned = false): void
    {
        $active = self::activeEmployeeIds(MealCoverageAssignment::query()
            ->whereIn('scope_type', ['city', 'place'])->distinct()->pluck('employee_id')->map(intval(...))->all());
        $manager = self::mealManagerEmployeeId();
        $allowUnassigned = $includeUnassigned && ($manager === null || self::activeEmployeeIds([$manager]) === []);
        $atPlace = static function (QueryBuilder $place) use ($column, $active): void {
            $place->selectRaw('1')->from('meal_coverages as place_coverage')
                ->where('place_coverage.scope_type', 'place')->whereIn('place_coverage.employee_id', $active)
                ->whereColumn('place_coverage.city_id', $column('city_id'))
                ->whereColumn('place_coverage.program_id', $column('program_id'))
                ->whereColumn('place_coverage.city_wide', $column('city_wide'))
                ->where(fn (QueryBuilder $target) => $target
                    ->whereColumn('place_coverage.center_id', $column('center_id'))
                    ->orWhere(fn (QueryBuilder $field) => $field->where('place_coverage.city_wide', true)
                        ->whereNull('place_coverage.center_id')->whereNull($column('center_id'))));
        };

        $query->where(function (Builder|QueryBuilder $result) use ($column, $employeeId, $atPlace, $active, $allowUnassigned): void {
            $result->whereExists(function (QueryBuilder $coverage) use ($column, $employeeId, $atPlace, $active): void {
                $coverage->selectRaw('1')->from('meal_coverages as coverage')
                    ->where('coverage.employee_id', $employeeId)
                    ->whereIn('coverage.employee_id', $active)
                    ->whereColumn('coverage.city_id', $column('city_id'))
                    ->where(fn (QueryBuilder $scope) => $scope
                        ->where(fn (QueryBuilder $direct) => $direct->where('coverage.scope_type', 'place')
                            ->whereExists(fn (QueryBuilder $place) => $atPlace($place->whereColumn('place_coverage.id', 'coverage.id'))))
                        ->orWhere(fn (QueryBuilder $city) => $city->where('coverage.scope_type', 'city')->whereNotExists($atPlace)));
            });

            if ($allowUnassigned) {
                $result->orWhere(fn (Builder|QueryBuilder $unassigned) => $unassigned
                    ->whereNotExists($atPlace)
                    ->whereNotExists(fn (QueryBuilder $city) => $city->selectRaw('1')->from('meal_coverages as city_coverage')
                        ->where('city_coverage.scope_type', 'city')
                        ->whereIn('city_coverage.employee_id', $active)
                        ->whereColumn('city_coverage.city_id', $column('city_id'))));
            }
        });
    }

    /**
     * Legacy annual-plan and execution city lookup. Y-15 moves these readers to location rows.
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
        $employeeId = MealCoverageAssignment::query()->where('scope_type', MealCoverageScope::City)->where('city_id', $cityId)->value('employee_id');

        return $employeeId === null ? null : (int) $employeeId;
    }

    /**
     * The MEAL Manager slot holder (contracts Â§9), null when the slot is
     * vacant. One meaning of vacant for every caller: Slots::holderOf().
     */
    public static function mealManagerEmployeeId(): ?int
    {
        return Slots::holderOf('meal_manager');
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
