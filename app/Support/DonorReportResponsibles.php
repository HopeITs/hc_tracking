<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\DonorReportType;
use App\Models\HrEmployee;
use App\Models\User;

/**
 * The people named as responsible on donor reports (contracts §14.3), read
 * from HR once per page or scan: their name, whether they can still act
 * (active in HR and holding a login) and their tracking user.
 *
 * When the named person cannot act, the default slot holder of the report's
 * type (narrative → Fundraising Manager, financial → Finance Manager) is the
 * one reminded, and the pages show a warning.
 */
final class DonorReportResponsibles
{
    /**
     * @param  list<int>  $employeeIds
     * @return array<int, array{employee: HrEmployee|null, user_id: int|null, reachable: bool}> employee id => state
     */
    public static function resolve(array $employeeIds): array
    {
        $employeeIds = array_values(array_unique(array_filter($employeeIds)));

        if ($employeeIds === []) {
            return [];
        }

        $employees = HrEmployee::query()->whereKey($employeeIds)->get()->keyBy('id');
        $users = User::query()->whereIn('employee_id', $employeeIds)->pluck('id', 'employee_id');
        $states = [];

        foreach ($employeeIds as $employeeId) {
            $employee = $employees->get($employeeId);
            $userId = $users->get($employeeId);

            $states[$employeeId] = [
                'employee' => $employee,
                'user_id' => $userId === null ? null : (int) $userId,
                'reachable' => $employee !== null && $employee->is_active && $userId !== null,
            ];
        }

        return $states;
    }

    /**
     * The default slot of a report type (contracts §14.3; no new slot).
     */
    public static function slotFor(DonorReportType $type): string
    {
        return match ($type) {
            DonorReportType::Narrative => 'fundraising_manager',
            DonorReportType::Financial => 'finance_manager',
        };
    }
}
