<?php

declare(strict_types=1);

namespace App\Actions\MonthlyReports;

use App\Enums\MonthlyReportStatus;
use App\Models\MonthlyReport;
use LogicException;

/**
 * Save the narrative answers (D-43a). Signature frozen at CP3; implemented
 * in T17A slice 4.
 */
final class SaveMonthlyReportAnswers
{
    /**
     * @param  list<array<string, mixed>>  $answers
     */
    public function handle(MonthlyReport $report, MonthlyReportStatus $expectedStatus, array $answers): MonthlyReport
    {
        throw new LogicException('Implemented in T17A slice 4');
    }
}
