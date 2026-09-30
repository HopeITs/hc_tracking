<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Models\Report;
use Illuminate\Support\Facades\Gate;

/**
 * Acknowledge that MEAL has reviewed a figure. One way on purpose.
 */
class MarkReportSeenByMeal
{
    public function handle(Report $report): void
    {
        Gate::authorize('markSeen', $report);

        $report->fill(['is_seen_by_meal' => true])->save();
    }
}
