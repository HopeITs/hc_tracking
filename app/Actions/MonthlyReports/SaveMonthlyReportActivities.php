<?php

declare(strict_types=1);

namespace App\Actions\MonthlyReports;

use App\Enums\MonthlyReportStatus;
use App\Models\MonthlyReport;
use LogicException;

/**
 * Save the activities this month (D-51). Signature frozen at CP3;
 * implemented in T17A slice 4.
 */
final class SaveMonthlyReportActivities
{
    /**
     * @param  list<array<string, mixed>>  $entries
     */
    public function handle(MonthlyReport $report, MonthlyReportStatus $expectedStatus, array $entries): MonthlyReport
    {
        throw new LogicException('Implemented in T17A slice 4');
    }
}
