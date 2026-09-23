<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\HrEmployee;
use App\Models\User;

/**
 * Maps HR employees (who are assigned, responsible, owners, managers) to the
 * tracking users that can receive notifications. An employee with no login
 * account maps to nobody.
 */
final class EmployeeRecipients
{
    /**
     * @param  array<int, int>  $employeeIds
     * @return array<int, int> employee id => user id
     */
    public function usersFor(array $employeeIds): array
    {
        $employeeIds = array_values(array_unique(array_filter($employeeIds)));

        if ($employeeIds === []) {
            return [];
        }

        return User::query()
            ->whereIn('employee_id', $employeeIds)
            ->pluck('id', 'employee_id')
            ->mapWithKeys(fn (int|string $userId, int|string $employeeId): array => [(int) $employeeId => (int) $userId])
            ->all();
    }

    /**
     * Both-language names, so a sentence can be rendered in either.
     *
     * @param  array<int, int>  $employeeIds
     * @return array<int, array{en: string, ar: string}>
     */
    public function names(array $employeeIds): array
    {
        $employeeIds = array_values(array_unique(array_filter($employeeIds)));

        if ($employeeIds === []) {
            return [];
        }

        return HrEmployee::query()
            ->whereIn('id', $employeeIds)
            ->get(HrEmployee::NameColumns)
            ->mapWithKeys(fn (HrEmployee $employee): array => [$employee->id => ['en' => $employee->name_en, 'ar' => $employee->name_ar]])
            ->all();
    }
}
