<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Enums\ReportStatus;
use App\Models\Report;
use Illuminate\Support\Facades\Gate;

/**
 * Send a reviewed or approved report back to draft, clearing submit_date
 * with it: a draft has not been submitted.
 */
class ReturnReportToDraft
{
    /**
     * @return bool false when the report is already a draft and nothing changed
     */
    public function handle(Report $report): bool
    {
        if (! in_array($report->status, [ReportStatus::Reviewed, ReportStatus::Approved], true)) {
            return false;
        }

        Gate::authorize('returnToDraft', $report);

        $report->fill(['status' => ReportStatus::Draft, 'submit_date' => null])->save();

        return true;
    }
}
