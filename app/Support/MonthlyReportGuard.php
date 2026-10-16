<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\MonthlyReportStatus;
use App\Models\MonthlyReport;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The common start of every T17A move (AGENT.md §5 concurrency): reload the
 * row with lockForUpdate(), refuse a stale expected status with the stale
 * message, then authorize on the locked row, so the decision is made on the
 * state that will be written. Call inside the action's transaction.
 */
final class MonthlyReportGuard
{
    /**
     * @param  array<int, mixed>  $extraArguments  further policy arguments after the report
     */
    public static function lock(MonthlyReport $report, MonthlyReportStatus $expectedStatus, string $ability, array $extraArguments = []): MonthlyReport
    {
        $locked = MonthlyReport::query()->with('project')->whereKey($report->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== $expectedStatus) {
            throw ValidationException::withMessages(['status' => __('monthly_reports.stale')]);
        }

        Gate::authorize($ability, [$locked, ...$extraArguments]);

        return $locked;
    }
}
