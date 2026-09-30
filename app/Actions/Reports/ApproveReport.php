<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Enums\ReportStatus;
use App\Models\Report;
use Illuminate\Support\Facades\Gate;

/**
 * Approve a reviewed report. Only valid from Reviewed.
 */
class ApproveReport
{
    /**
     * @return bool false when the report is not under review and nothing changed
     */
    public function handle(Report $report): bool
    {
        if ($report->status !== ReportStatus::Reviewed) {
            return false;
        }

        Gate::authorize('approve', $report);

        $report->fill(['status' => ReportStatus::Approved])->save();

        return true;
    }
}
