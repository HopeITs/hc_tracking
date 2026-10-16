<?php

declare(strict_types=1);

namespace App\Actions\MonthlyReports;

use App\Models\MonthlyReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * MEAL acknowledges a submitted or validated report (`reports.mark-seen`):
 * `seen_by_meal_at` is stamped once and never moved.
 */
final class MarkMonthlyReportSeen
{
    /**
     * @return bool false when the report was already seen and nothing changed
     */
    public function handle(MonthlyReport $report): bool
    {
        return DB::transaction(function () use ($report): bool {
            $locked = MonthlyReport::query()->with('project')->whereKey($report->id)->lockForUpdate()->firstOrFail();

            Gate::authorize('markSeen', $locked);

            if ($locked->seen_by_meal_at !== null) {
                return false;
            }

            $locked->fill(['seen_by_meal_at' => now()])->save();

            return true;
        });
    }
}
