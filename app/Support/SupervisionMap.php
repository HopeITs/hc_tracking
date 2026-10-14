<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\HrEmployee;
use App\Models\Supervision;

/**
 * Who supervises whom (contracts §10, D-13): the HR direct manager of each
 * employee's current position (EmployeeProfile::managerOf()) plus the local
 * supervisions rows, which hold exceptions and additions only.
 *
 * Only employees active in HR take part, on either side. Results are
 * de-duplicated, sorted and memoized for the request or job: the instance is
 * scoped in AppServiceProvider, and the writers call flush(). One load costs
 * one query on supervisions and one HR query for the active flags; the HR
 * managers come from EmployeeProfile's cached lookup. Supervision grants no
 * permission and no visibility.
 */
final class SupervisionMap
{
    /**
     * @var array<int, true>|null active HR employee id => true
     */
    private ?array $activeEmployees = null;

    /**
     * @var array<int, list<int>>|null local rows between active employees: supervisee id => supervisor ids
     */
    private ?array $localSupervisors = null;

    /**
     * @var array<int, list<int>> supervisee id => active supervisor ids
     */
    private array $supervisors = [];

    /**
     * @var array<int, list<int>>|null supervisor id => active supervisee ids, over everyone
     */
    private ?array $supervisees = null;

    /**
     * Active supervisee employee ids: HR direct reports of the supervisor plus local rows.
     *
     * @return list<int>
     */
    public static function superviseesOf(int $supervisorEmployeeId): array
    {
        return self::instance()->allSupervisees()[$supervisorEmployeeId] ?? [];
    }

    /**
     * @param  list<int>  $superviseeEmployeeIds
     * @return array<int, list<int>> supervisee id => active supervisor employee ids
     */
    public static function supervisorsOf(array $superviseeEmployeeIds): array
    {
        $map = self::instance();
        $result = [];

        foreach ($superviseeEmployeeIds as $superviseeId) {
            $result[$superviseeId] = $map->supervisorsOfOne($superviseeId);
        }

        return $result;
    }

    public static function supervises(int $supervisorEmployeeId, int $superviseeEmployeeId): bool
    {
        return in_array($supervisorEmployeeId, self::instance()->supervisorsOfOne($superviseeEmployeeId), true);
    }

    /**
     * Forget what this request has read, after a supervision was added or removed.
     */
    public static function flush(): void
    {
        app()->forgetInstance(self::class);
    }

    private static function instance(): self
    {
        return app(self::class);
    }

    /**
     * @return list<int>
     */
    private function supervisorsOfOne(int $superviseeId): array
    {
        if (array_key_exists($superviseeId, $this->supervisors)) {
            return $this->supervisors[$superviseeId];
        }

        $active = $this->activeEmployees();

        if (! isset($active[$superviseeId])) {
            return $this->supervisors[$superviseeId] = [];
        }

        $ids = [];
        $manager = EmployeeProfile::managerOf($superviseeId);

        if ($manager !== null && $manager !== $superviseeId && isset($active[$manager])) {
            $ids[] = $manager;
        }

        return $this->supervisors[$superviseeId] = self::sortedUnique([...$ids, ...$this->localSupervisors()[$superviseeId] ?? []]);
    }

    /**
     * The whole map turned around, built once: every active employee's
     * supervisors, keyed by supervisor.
     *
     * @return array<int, list<int>>
     */
    private function allSupervisees(): array
    {
        if ($this->supervisees !== null) {
            return $this->supervisees;
        }

        $supervisees = [];

        foreach (array_keys($this->activeEmployees()) as $employeeId) {
            foreach ($this->supervisorsOfOne($employeeId) as $supervisorId) {
                $supervisees[$supervisorId][] = $employeeId;
            }
        }

        return $this->supervisees = array_map(self::sortedUnique(...), $supervisees);
    }

    /**
     * @return array<int, true>
     */
    private function activeEmployees(): array
    {
        if ($this->activeEmployees === null) {
            $ids = HrEmployee::query()->toBase()->where('is_active', true)->pluck('id')->all();
            $this->activeEmployees = array_fill_keys(array_map(intval(...), $ids), true);
        }

        return $this->activeEmployees;
    }

    /**
     * @return array<int, list<int>>
     */
    private function localSupervisors(): array
    {
        if ($this->localSupervisors === null) {
            $active = $this->activeEmployees();
            $this->localSupervisors = [];

            foreach (Supervision::query()->toBase()->get(['supervisor_employee_id', 'supervisee_employee_id']) as $row) {
                $supervisorId = (int) $row->supervisor_employee_id;
                $superviseeId = (int) $row->supervisee_employee_id;

                if ($supervisorId !== $superviseeId && isset($active[$supervisorId], $active[$superviseeId])) {
                    $this->localSupervisors[$superviseeId][] = $supervisorId;
                }
            }
        }

        return $this->localSupervisors;
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private static function sortedUnique(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }
}
