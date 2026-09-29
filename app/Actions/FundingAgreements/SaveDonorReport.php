<?php

declare(strict_types=1);

namespace App\Actions\FundingAgreements;

use App\Models\DonorReport;
use Illuminate\Support\Facades\Gate;

/**
 * Record a report owed to a donor, or update one, from already-validated input.
 */
class SaveDonorReport
{
    /**
     * @param  array<string, mixed>  $validated  keyed by the report form's property names
     */
    public function handle(int $agreementId, ?DonorReport $existing, array $validated): DonorReport
    {
        Gate::authorize('funding-agreements.update');

        $report = $existing ?? new DonorReport;

        $report->fill([
            'donor_proposal_id' => $agreementId,
            'title' => $validated['reportTitle'],
            'type' => $validated['reportType'],
            'due_on' => $validated['reportDueOn'],
            'submitted_on' => $validated['reportSubmittedOn'] === '' ? null : $validated['reportSubmittedOn'],
        ])->save();

        return $report;
    }
}
