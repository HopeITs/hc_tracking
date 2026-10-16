<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\MonthlyReport;

/**
 * `project_activity_statuses` written on Validate and restored on a granted
 * reopen (contracts §13, §13.1 item 8, D-51).
 *
 * Slice 3 calls these hooks from ValidateMonthlyReport and
 * DecideMonthlyReportReopen inside their transaction; the bodies land in
 * T17A slice 4 with the activity entries.
 */
final class MonthlyReportActivityStatuses
{
    /**
     * Write the statuses confirmed by the report.
     *
     * @return array<string, mixed> the previous state of each touched row, stored in the `monthly_report.validated` activity properties
     */
    public function confirm(MonthlyReport $report): array
    {
        return [];
    }

    /**
     * Restore the statuses this report's validation replaced.
     */
    public function restore(MonthlyReport $report): void {}
}
