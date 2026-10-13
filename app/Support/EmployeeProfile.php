<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Locale;
use App\Models\Concerns\RemembersHrLookup;
use App\Models\HrContract;
use App\Models\HrEmployee;
use Illuminate\Support\Facades\Log;

/**
 * The one read-only view of where an employee sits in HR: their current
 * position (from the active contract), job level, department, program,
 * center, city and direct manager.
 *
 * Nothing here is copied into tracking tables. Every active contract is read
 * in one HR query (plus one for the employees' active flags) and kept for the
 * running application, reloaded after a minute or when an unknown employee id
 * is asked for (see RemembersHrLookup). The queries name their columns, so a
 * contract's salary, currency and type are never read.
 *
 * Current position = the active contract; with several active contracts the
 * one with the latest start date wins and a warning is logged.
 */
final class EmployeeProfile
{
    use RemembersHrLookup;

    /**
     * The employee's current place in HR, or null when they hold no active contract.
     *
     * @return array{employee_id: int, position_id: ?int, position_title_en: ?string, position_title_ar: ?string, job_level: ?string, grade: ?int,
     *               department_id: ?int, program_id: ?int, center_id: ?int, city_id: ?int, direct_manager_employee_id: ?int, is_active: bool}|null
     */
    public static function for(int $employeeId): ?array
    {
        /** @var array<string, mixed>|false|null $entry */
        $entry = self::rememberedHrLookupEntry($employeeId, fn (): array => self::load());

        if (! is_array($entry)) {
            return null;
        }

        $levelEn = $entry['job_level_en'];
        $levelAr = $entry['job_level_ar'];

        return [
            'employee_id' => $employeeId,
            'position_id' => $entry['position_id'],
            'position_title_en' => $entry['position_title_en'],
            'position_title_ar' => $entry['position_title_ar'],
            'job_level' => Locale::current() === Locale::Arabic && $levelAr !== null && $levelAr !== '' ? $levelAr : $levelEn,
            'grade' => $entry['grade'],
            'department_id' => $entry['department_id'],
            'program_id' => $entry['program_id'],
            'center_id' => $entry['center_id'],
            'city_id' => $entry['city_id'],
            'direct_manager_employee_id' => $entry['direct_manager_position_id'] === null
                ? null
                : self::holderOfPosition($entry['direct_manager_position_id']),
            'is_active' => $entry['is_active'],
        ];
    }

    /**
     * The employees whose current position is at a center.
     *
     * @return list<int>
     */
    public static function employeeIdsAtCenter(int $centerId): array
    {
        return self::employeeIdsWhere('center_id', $centerId);
    }

    /**
     * The employees whose current position is in a department.
     *
     * @return list<int>
     */
    public static function employeeIdsInDepartment(int $departmentId): array
    {
        return self::employeeIdsWhere('department_id', $departmentId);
    }

    /**
     * The HR direct manager: the holder of the employee's position's
     * direct-manager position, null when there is none or it is vacant.
     */
    public static function managerOf(int $employeeId): ?int
    {
        return self::for($employeeId)['direct_manager_employee_id'] ?? null;
    }

    /**
     * The employee currently holding a position (latest start date), null when vacant.
     */
    public static function holderOfPosition(int $positionId): ?int
    {
        $holder = null;
        $holderStart = '';

        foreach (self::rememberedHrLookup(fn (): array => self::load()) as $employeeId => $entry) {
            if (is_array($entry) && $entry['position_id'] === $positionId && ($holder === null || [$entry['start_date'], $employeeId] > [$holderStart, $holder])) {
                $holder = $employeeId;
                $holderStart = $entry['start_date'];
            }
        }

        return $holder;
    }

    /**
     * @return list<int>
     */
    private static function employeeIdsWhere(string $key, int $value): array
    {
        $ids = [];

        foreach (self::rememberedHrLookup(fn (): array => self::load()) as $employeeId => $entry) {
            if (is_array($entry) && $entry[$key] === $value) {
                $ids[] = $employeeId;
            }
        }

        return $ids;
    }

    /**
     * Every HR employee's current contract, keyed by employee id; false for an
     * employee HR has but who holds no active contract, so asking about them
     * does not count as an unknown id and reload the lookup.
     *
     * @return array<int, array{position_id: int, start_date: string, position_title_en: ?string, position_title_ar: ?string, job_level_en: ?string, job_level_ar: ?string,
     *                          grade: ?int, department_id: ?int, program_id: ?int, center_id: ?int, city_id: ?int, direct_manager_position_id: ?int, is_active: bool}|false>
     */
    private static function load(): array
    {
        $rows = HrContract::query()
            ->toBase()
            ->join('positions', 'positions.id', '=', 'contracts.position_id')
            ->leftJoin('job_levels', 'job_levels.id', '=', 'positions.job_level_id')
            ->leftJoin('centers', 'centers.id', '=', 'positions.center_id')
            ->where('contracts.is_active', true)
            ->orderByDesc('contracts.start_date')
            ->orderByDesc('contracts.id')
            ->get([
                'contracts.employee_id',
                'contracts.position_id',
                'contracts.start_date',
                'positions.title_en',
                'positions.title_ar',
                'positions.department_id',
                'positions.program_id',
                'positions.center_id',
                'positions.city_id as position_city_id',
                'positions.direct_manager_id',
                'centers.city_id as center_city_id',
                'job_levels.name_en as job_level_en',
                'job_levels.name_ar as job_level_ar',
                'job_levels.grade',
            ]);

        $activeEmployees = HrEmployee::query()->toBase()->pluck('is_active', 'id');

        $entries = array_fill_keys(array_map(intval(...), $activeEmployees->keys()->all()), false);

        foreach ($rows as $row) {
            $employeeId = (int) $row->employee_id;

            if (is_array($entries[$employeeId] ?? null)) {
                Log::warning('HR employee has several active contracts; using the latest start date.', ['employee_id' => $employeeId]);

                continue;
            }

            $centerId = self::nullableInt($row->center_id);

            $entries[$employeeId] = [
                'position_id' => (int) $row->position_id,
                'start_date' => (string) $row->start_date,
                'position_title_en' => $row->title_en,
                'position_title_ar' => $row->title_ar,
                'job_level_en' => $row->job_level_en,
                'job_level_ar' => $row->job_level_ar,
                'grade' => self::nullableInt($row->grade),
                'department_id' => self::nullableInt($row->department_id),
                'program_id' => self::nullableInt($row->program_id),
                'center_id' => $centerId,
                'city_id' => self::nullableInt($centerId === null ? $row->position_city_id : $row->center_city_id),
                'direct_manager_position_id' => self::nullableInt($row->direct_manager_id),
                'is_active' => (bool) ($activeEmployees[$employeeId] ?? false),
            ];
        }

        return $entries;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
