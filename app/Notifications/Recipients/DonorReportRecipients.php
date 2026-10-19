<?php

declare(strict_types=1);

namespace App\Notifications\Recipients;

use App\Models\Activity;
use App\Models\DonorReport;
use App\Models\User;

/**
 * Donor reports (contracts §14.3, §15.2, T19). Oversight comes from the
 * event catalog.
 *
 * - Direct: the responsible employee named on the report.
 * - Watchers: Financial and the Public Relations Manager (as the agreement).
 * - The responsible person hears about their own report without
 *   funding-agreements.view (they are routed to My Reporting Duties).
 */
final class DonorReportRecipients extends WatcherRecipients
{
    public function directEmployeeIds(Activity $activity): array
    {
        return $this->uniqueIds([$this->responsibleEmployeeId($activity)]);
    }

    public function permissionExemptUserIds(Activity $activity): array
    {
        $employeeId = $this->responsibleEmployeeId($activity);

        return $employeeId === null
            ? []
            : array_values(User::query()->where('employee_id', $employeeId)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
    }

    protected function roles(): array
    {
        return ['Financial', 'Public Relations Manager'];
    }

    private function responsibleEmployeeId(Activity $activity): ?int
    {
        $employeeId = DonorReport::query()->whereKey($activity->subject_id)->value('responsible_employee_id');

        return $employeeId === null ? null : (int) $employeeId;
    }
}
