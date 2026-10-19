<?php

declare(strict_types=1);

namespace App\Actions\FundingAgreements;

use App\Models\DonorReport;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Record a report owed to a donor, or update one, from already-validated input.
 * Submission goes through MarkDonorReportSubmitted only (date + file, D-21);
 * a submitted report is frozen.
 */
class SaveDonorReport
{
    /**
     * @param  array<string, mixed>  $validated  keyed by the report form's property names
     *
     * @throws ValidationException already_submitted
     */
    public function handle(int $agreementId, ?DonorReport $existing, array $validated): DonorReport
    {
        Gate::authorize('funding-agreements.update');

        if ($existing?->isSubmitted() === true) {
            throw ValidationException::withMessages(['reportTitle' => __('donor_reports.already_submitted')]);
        }

        $report = $existing ?? new DonorReport;

        $report->fill([
            'donor_proposal_id' => $agreementId,
            'title' => $validated['reportTitle'],
            'type' => $validated['reportType'],
            'due_on' => $validated['reportDueOn'],
        ])->save();

        return $report;
    }
}
