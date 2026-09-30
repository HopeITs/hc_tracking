<?php

declare(strict_types=1);

namespace App\Actions\FundingAgreements;

use App\Models\DonorReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

/**
 * Record that a donor report went out today.
 */
class MarkDonorReportSubmitted
{
    public function handle(DonorReport $report): void
    {
        Gate::authorize('funding-agreements.update');

        if (! $report->isSubmitted()) {
            $report->update(['submitted_on' => CarbonImmutable::today()->toDateString()]);
        }
    }
}
