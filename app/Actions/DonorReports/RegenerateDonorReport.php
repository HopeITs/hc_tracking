<?php

declare(strict_types=1);

namespace App\Actions\DonorReports;

use App\Models\DonorReport;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Compose a donor report again from today's validated figures, as a new row
 * with version + 1 (same agreement, type and period). The old version stays
 * as it was; a submitted report is never regenerated (contracts §14.3).
 *
 * - Who: `regenerate` on the report (`reports.aggregate`, not submitted) and
 *   `view` on its proposal.
 * - The period is kept as saved, even if the grant dates changed since.
 */
final class RegenerateDonorReport
{
    public function __construct(private readonly ComposeDonorReport $composer) {}

    /**
     * @throws ValidationException proposal_required, self_funded, already_submitted
     */
    public function handle(DonorReport $report, User $actor): DonorReport
    {
        Gate::forUser($actor)->authorize('regenerate', $report);

        $proposalId = (int) $report->donorProposal()->value('proposal_id');
        $proposal = $this->composer->eligibleProposal($proposalId, $actor);
        $snapshot = $this->composer->snapshot($proposal, $report->period_type, $report->period_start, $report->period_end, $actor);

        return SaveDonorReportSnapshot::insertNextVersion($report->donor_proposal_id, [
            'title' => $report->title,
            'type' => $report->type,
            'audience' => $report->audience,
            'period_type' => $report->period_type,
            'period_start' => $report->period_start->toDateString(),
            'period_end' => $report->period_end->toDateString(),
            'due_on' => $report->due_on->toDateString(),
            'snapshot' => $snapshot,
            'responsible_employee_id' => $report->responsible_employee_id,
            'notes' => $report->notes,
        ], function () use ($report): void {
            if (DonorReport::query()->whereKey($report->id)->lockForUpdate()->value('submitted_on') !== null) {
                throw ValidationException::withMessages(['report' => __('donor_reports.already_submitted')]);
            }
        });
    }
}
