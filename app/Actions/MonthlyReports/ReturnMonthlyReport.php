<?php

declare(strict_types=1);

namespace App\Actions\MonthlyReports;

use App\Enums\MonthlyReportStatus;
use App\Models\MonthlyReport;
use App\Support\MonthlyReportGuard;
use App\Support\MonthlyReportMirrors;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Send a submitted report back to the manager (contracts §13 Workflow, D-07):
 * a reason of at least 10 characters; the report and its mirrors return to
 * draft, `submit_date` is cleared, and no activity status is written.
 */
final class ReturnMonthlyReport
{
    public function __construct(private MonthlyReportMirrors $mirrors) {}

    public function handle(MonthlyReport $report, MonthlyReportStatus $expectedStatus, string $reason): MonthlyReport
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 10) {
            throw ValidationException::withMessages(['reason' => __('monthly_reports.reason_min')]);
        }

        return DB::transaction(function () use ($report, $expectedStatus, $reason): MonthlyReport {
            $locked = MonthlyReportGuard::lock($report, $expectedStatus, 'return');

            $locked->fill([
                'status' => MonthlyReportStatus::Draft,
                'returned_at' => now(),
                'returned_by' => (int) Auth::id(),
                'return_reason' => $reason,
            ])->save();

            $this->mirrors->moveTo($locked, null);

            return $locked;
        });
    }
}
