<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\HrContract;
use App\Models\HrEmployee;
use App\Models\HrPosition;
use App\Models\Supervision;

/**
 * Who supervises whom (contracts §10, D-13): the HR org chart plus the local
 * supervisions rows, which hold exceptions and additions only.
 *
 * The org chart is HR's positions.direct_manager_id tree. An employee's HR
 * supervisors are every active holder of their position's manager position;
 * when that position is vacant the chart is climbed to the next position up
 * that someone holds (the link is then marked climbed). See hrSupervisorsOf().
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
     * @var array<int, array{ids: list<int>, climbed: bool}>|null active employee id => HR supervisors
     */
    private ?array $hrSupervisors = null;

    /**
     * @var array<int, array{managerId: int|null, title_en: string|null, title_ar: string|null}>|null position id => its row
     */
    private ?array $positions = null;

    /**
     * @var array<int, list<int>>|null position id => active holder employee ids
     */
    private ?array $holders = null;

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
     * Every supervision link for the Supervision tab: HR direct-manager links
     * between active employees (supervisionId null), then every local row,
     * inactive people included so an admin can still remove them.
     *
     * @return list<array{supervisor: int, supervisee: int, supervisionId: int|null, climbed: bool}>
     */
    public static function links(): array
    {
        $links = [];

        foreach (self::instance()->allHrSupervisors() as $employeeId => $hr) {
            foreach ($hr['ids'] as $managerId) {
                $links[] = ['supervisor' => $managerId, 'supervisee' => $employeeId, 'supervisionId' => null, 'climbed' => $hr['climbed']];
            }
        }

        foreach (Supervision::query()->toBase()->orderBy('id')->get(['id', 'supervisor_employee_id', 'supervisee_employee_id']) as $row) {
            $links[] = ['supervisor' => (int) $row->supervisor_employee_id, 'supervisee' => (int) $row->supervisee_employee_id, 'supervisionId' => (int) $row->id, 'climbed' => false];
        }

        return $links;
    }

    /**
     * Active HR employee ids.
     *
     * @return list<int>
     */
    public static function activeEmployeeIds(): array
    {
        return array_keys(self::instance()->activeEmployees());
    }

    /**
     * The HR org-chart supervisors of an active employee: every active holder
     * of their position's manager position, climbing past vacant positions.
     *
     * @return list<int>
     */
    public static function hrSupervisorsOf(int $employeeId): array
    {
        return self::instance()->allHrSupervisors()[$employeeId]['ids'] ?? [];
    }

    /**
     * What in HR keeps the org chart from being read cleanly, for the Supervision tab's "Fix in HR" list.
     *
     * @return array{
     *     noContract: list<int>,
     *     severalContracts: list<int>,
     *     vacantSeats: list<array{positionId: int, title_en: string|null, title_ar: string|null, staff: list<int>}>,
     *     sharedSeats: list<array{positionId: int, title_en: string|null, title_ar: string|null, holders: list<int>}>
     * }
     */
    public static function hrIssues(): array
    {
        $map = self::instance();
        $positions = $map->positions();
        $holders = $map->holders();
        $active = $map->activeEmployees();

        $noContract = array_values(array_filter(array_keys($active), fn (int $id): bool => (EmployeeProfile::for($id)['position_id'] ?? null) === null));

        $severalContracts = array_values(array_filter(array_map(
            intval(...),
            HrContract::query()->toBase()->where('is_active', true)->groupBy('employee_id')->havingRaw('count(*) > 1')->pluck('employee_id')->all(),
        ), fn (int $id): bool => isset($active[$id])));

        /* Vacant manager positions that a chain had to climb past, with the staff right below them. */
        $vacant = [];
        $managing = [];

        foreach ($holders as $positionId => $people) {
            $managerPosition = $positions[$positionId]['managerId'] ?? null;

            if ($managerPosition === null) {
                continue;
            }

            $managing[$managerPosition] = true;

            if (($holders[$managerPosition] ?? []) === []) {
                $vacant[$managerPosition] ??= ['positionId' => $managerPosition, 'title_en' => $positions[$managerPosition]['title_en'] ?? null, 'title_ar' => $positions[$managerPosition]['title_ar'] ?? null, 'staff' => []];
                $vacant[$managerPosition]['staff'] = [...$vacant[$managerPosition]['staff'], ...$people];
            }
        }

        $shared = [];

        foreach ($holders as $positionId => $people) {
            if (count($people) > 1 && isset($managing[$positionId])) {
                $shared[] = ['positionId' => $positionId, 'title_en' => $positions[$positionId]['title_en'] ?? null, 'title_ar' => $positions[$positionId]['title_ar'] ?? null, 'holders' => $people];
            }
        }

        return [
            'noContract' => $noContract,
            'severalContracts' => $severalContracts,
            'vacantSeats' => array_values($vacant),
            'sharedSeats' => $shared,
        ];
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

        return $this->supervisors[$superviseeId] = self::sortedUnique([...$this->allHrSupervisors()[$superviseeId]['ids'] ?? [], ...$this->localSupervisors()[$superviseeId] ?? []]);
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
     * Every active employee's HR supervisors, built once from the org chart.
     *
     * @return array<int, array{ids: list<int>, climbed: bool}>
     */
    private function allHrSupervisors(): array
    {
        if ($this->hrSupervisors !== null) {
            return $this->hrSupervisors;
        }

        $positions = $this->positions();
        $holders = $this->holders();
        $result = [];

        foreach (array_keys($this->activeEmployees()) as $employeeId) {
            $positionId = EmployeeProfile::for($employeeId)['position_id'] ?? null;

            if ($positionId === null) {
                continue;
            }

            $managerPosition = $positions[$positionId]['managerId'] ?? null;
            $seen = [$positionId => true];
            $climbed = false;

            while ($managerPosition !== null && ! isset($seen[$managerPosition])) {
                $seen[$managerPosition] = true;
                $ids = array_values(array_filter($holders[$managerPosition] ?? [], fn (int $id): bool => $id !== $employeeId));

                if ($ids !== []) {
                    $result[$employeeId] = ['ids' => self::sortedUnique($ids), 'climbed' => $climbed];

                    break;
                }

                $climbed = true;
                $managerPosition = $positions[$managerPosition]['managerId'] ?? null;
            }
        }

        return $this->hrSupervisors = $result;
    }

    /**
     * The org chart: one HR query, ids and titles only.
     *
     * @return array<int, array{managerId: int|null, title_en: string|null, title_ar: string|null}>
     */
    private function positions(): array
    {
        if ($this->positions === null) {
            $this->positions = [];

            foreach (HrPosition::query()->toBase()->get(['id', 'direct_manager_id', 'title_en', 'title_ar']) as $row) {
                $this->positions[(int) $row->id] = [
                    'managerId' => $row->direct_manager_id === null ? null : (int) $row->direct_manager_id,
                    'title_en' => $row->title_en,
                    'title_ar' => $row->title_ar,
                ];
            }
        }

        return $this->positions;
    }

    /**
     * Who sits in each position now: active employees by their current position.
     *
     * @return array<int, list<int>>
     */
    private function holders(): array
    {
        if ($this->holders === null) {
            $this->holders = [];

            foreach (array_keys($this->activeEmployees()) as $employeeId) {
                $positionId = EmployeeProfile::for($employeeId)['position_id'] ?? null;

                if ($positionId !== null) {
                    $this->holders[$positionId][] = $employeeId;
                }
            }
        }

        return $this->holders;
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
