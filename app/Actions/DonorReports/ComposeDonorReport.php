<?php

declare(strict_types=1);

namespace App\Actions\DonorReports;

use App\Enums\ReportingPeriod;
use App\Models\Proposal;
use App\Models\User;
use App\Support\DonorReportAggregator;
use App\Support\DonorReportPeriods;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Preview a donor report: one proposal's validated monthly figures for a
 * period, without saving anything (contracts §14.3).
 *
 * - Who: `reports.aggregate` and `view` on the proposal.
 * - Which proposals: approved by the donor and not self-funded (D-27a); the
 *   id is re-loaded here, never trusted.
 * - Finance is included only for holders of funding-agreements.view.
 */
final class ComposeDonorReport
{
    public function __construct(private readonly DonorReportAggregator $aggregator) {}

    /**
     * @return array<string, mixed> the snapshot payload
     *
     * @throws ValidationException proposal_required, self_funded, period_outside_grant
     */
    public function handle(int $proposalId, ReportingPeriod $period, string $startMonth, ?string $endMonth, User $actor): array
    {
        Gate::forUser($actor)->authorize('reports.aggregate');

        $proposal = $this->eligibleProposal($proposalId, $actor);
        $window = DonorReportPeriods::window($proposal, $period, $startMonth, $endMonth);

        return $this->snapshot($proposal, $period, $window['start'], $window['end'], $actor);
    }

    /**
     * The proposal a donor report may be built on, re-loaded from its id:
     * donor-approved, visible to the actor and not self-funded.
     *
     * @throws ValidationException proposal_required, self_funded
     */
    public function eligibleProposal(int $proposalId, User $actor): Proposal
    {
        $proposal = Proposal::query()->with('donor')->find($proposalId);

        if ($proposal === null || ! $proposal->status->isApprovedByDonor() || Gate::forUser($actor)->denies('view', $proposal)) {
            throw ValidationException::withMessages(['proposalId' => __('donor_reports.proposal_required')]);
        }

        if ($proposal->donor->is_internal) {
            throw ValidationException::withMessages(['proposalId' => __('donor_reports.self_funded')]);
        }

        return $proposal;
    }

    /**
     * The aggregate stamped with who generated it, when, and the period type.
     *
     * @return array<string, mixed>
     */
    public function snapshot(Proposal $proposal, ReportingPeriod $period, CarbonImmutable $start, CarbonImmutable $end, User $actor): array
    {
        $aggregate = $this->aggregator->aggregate($proposal, $start, $end, $actor->can('funding-agreements.view'));
        $aggregate['period'] = ['type' => $period->value, ...$aggregate['period']];

        return [
            'schema_version' => $aggregate['schema_version'],
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'generated_by' => $actor->id,
            ...$aggregate,
        ];
    }
}
