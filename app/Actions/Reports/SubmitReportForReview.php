<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Enums\ReportStatus;
use App\Models\Report;
use Illuminate\Support\Facades\Gate;

/**
 * Submit a draft for review.
 *
 * Writes status and submit_date together so the two can never disagree about
 * whether, and when, a report was submitted.
 */
class SubmitReportForReview
{
    /**
     * @return bool false when the report is not a draft and nothing changed
     */
    public function handle(Report $report): bool
    {
        if ($report->status !== ReportStatus::Draft) {
            return false;
        }

        Gate::authorize('submit', $report);

        $report->fill([
            'status' => ReportStatus::Reviewed,
            'submit_date' => now()->toDateString(),
        ])->save();

        return true;
    }
}
