<?php

declare(strict_types=1);

namespace App\Actions\MonthlyReports;

use App\Enums\MonthlyReportStatus;
use App\Models\MonthlyReport;
use App\Support\MonthlyReportGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Delete a draft that was never submitted and has no reopen request
 * (contracts §13). Its lines and mirrors go with it; its evidence files
 * leave the disk after commit (slice 4). Reopen requests are never deleted.
 */
final class DeleteMonthlyReport
{
    public function handle(MonthlyReport $report, MonthlyReportStatus $expectedStatus): void
    {
        DB::transaction(function () use ($report, $expectedStatus): void {
            $locked = MonthlyReportGuard::lock($report, $expectedStatus, 'delete');

            if ($locked->reopenRequests()->exists()) {
                throw ValidationException::withMessages(['status' => __('monthly_reports.cannot_delete')]);
            }

            $locked->delete();
        });
    }
}
