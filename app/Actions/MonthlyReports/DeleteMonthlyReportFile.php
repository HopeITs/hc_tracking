<?php

declare(strict_types=1);

namespace App\Actions\MonthlyReports;

use App\Enums\MonthlyReportStatus;
use App\Models\MonthlyReport;
use LogicException;

/**
 * Remove an evidence file (D-42); it leaves the disk after commit. Signature
 * frozen at CP3; implemented in T17A slice 4.
 */
final class DeleteMonthlyReportFile
{
    public function handle(MonthlyReport $report, MonthlyReportStatus $expectedStatus, int $fileId): void
    {
        throw new LogicException('Implemented in T17A slice 4');
    }
}
