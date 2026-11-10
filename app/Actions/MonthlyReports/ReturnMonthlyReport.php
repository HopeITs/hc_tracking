<?php

declare(strict_types=1);

namespace App\Actions\MonthlyReports;

use App\Enums\MonthlyReportStatus;
use App\Models\MonthlyReport;
use App\Support\MonthlyReportGuard;
use App\Support\MonthlyReportMirrors;
use App\Support\MonthlyReportPeriods;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Send a submitted report back to the manager (contracts §13 Workflow, D-07):
 * a reason of at least 10 characters; the report and its mirrors return to
 * draft, `submit_date` is cleared, and no activity status is written. A
 * report returned after its deadline gets a new one, the next working day
 * (MonthlyReportPeriods::revisedDueDateWhenSentBack()).
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
            $project = $locked->project()->firstOrFail();

            $locked->fill([
                'status' => MonthlyReportStatus::Draft,
                'returned_at' => now(),
                'returned_by' => (int) Auth::id(),
                'return_reason' => $reason,
                // Dynamic Deadline rule: returned after its deadline, the report is due by the
                // next working day (weekends and holidays skipped); otherwise it keeps its own.
                'revised_due_on' => MonthlyReportPeriods::for($project)->revisedDueDateWhenSentBack($locked, CarbonImmutable::now()),
            ])->save();

            $this->mirrors->moveTo($locked, null);

            return $locked;
        });
    }
}
