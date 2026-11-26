<?php

declare(strict_types=1);

namespace App\Actions\Locations;

use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Support\Locations\LocationPeriods;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Give a location row the dates of its proposal and, once its responsible
 * has acknowledged, move a draft to `approved` (contracts.md §3, §6, Y-17).
 *
 * The twin of the former ApproveProjectFromProposal, moved onto the row.
 * Two paths reach it, and either may come first: the proposal's approval
 * (`proposal_approval`) and the responsible's acknowledgement
 * (`owner_acknowledgement`). Both lock the row and then its proposal, in
 * that order, so the second finds the first's work done. The call is
 * idempotent: it returns true only on the call that moved the status to
 * `approved`.
 *
 * A row belongs to exactly one proposal (D-93), so its dates are that
 * proposal's grant window — there is no window to merge across grants, as
 * the project had. Dates are never shortened (LocationPeriods::widen).
 */
final class ApproveLocationFromProposal
{
    /** @var list<string> */
    public const VIAS = ['proposal_approval', 'owner_acknowledgement'];

    /**
     * @param  string  $via  proposal_approval or owner_acknowledgement
     */
    public function handle(ProposalLocation $row, string $via): bool
    {
        if (! in_array($via, self::VIAS, true)) {
            throw new InvalidArgumentException("Unknown approval path [{$via}].");
        }

        return DB::transaction(function () use ($row, $via): bool {
            // Lock order everywhere on this path: the row, then its proposal
            // (quality-bar.md §2).
            $locked = ProposalLocation::query()->whereKey($row->getKey())->lockForUpdate()->firstOrFail();
            $proposal = Proposal::query()->whereKey($locked->proposal_id)->lockForUpdate()->firstOrFail();

            if ($via === 'proposal_approval') {
                Gate::authorize('approve', $proposal);
            } else {
                $assignment = $locked->currentAssignment('responsible_employee_id');

                if ($assignment === null) {
                    return false;
                }

                Gate::authorize('approveProject', $assignment);
            }

            if (! $this->isFunding($proposal) || $locked->isOwnerRejected()) {
                return false;
            }

            return match ($locked->status) {
                ProjectStatus::Draft => $this->approveDraft($locked, $proposal, $via),
                ProjectStatus::Approved, ProjectStatus::Active => $this->extendDates($locked, $proposal),
                ProjectStatus::Submitted, ProjectStatus::Closed, ProjectStatus::Archived => false,
            };
        });
    }

    /**
     * A draft takes its proposal's window and, with an acknowledged
     * responsible, becomes approved.
     */
    private function approveDraft(ProposalLocation $row, Proposal $proposal, string $via): bool
    {
        $row->start_date = $proposal->actual_date_begin;
        $row->end_date = $proposal->effectiveDateEnd();

        if (! $row->isAssignmentAcknowledged('responsible_employee_id')) {
            $row->save();

            return false;
        }

        $row->status = ProjectStatus::Approved;
        $row->save();
        $row->recordActivity('project.approved', properties: ['via' => $via, 'proposal_id' => $proposal->id]);

        return true;
    }

    /**
     * An approved or active row keeps its status and only ever grows: the
     * grant's window may have moved out, never in.
     */
    private function extendDates(ProposalLocation $row, Proposal $proposal): bool
    {
        LocationPeriods::widen($row, $proposal->actual_date_begin, $proposal->effectiveDateEnd());

        return false;
    }

    /**
     * The row's proposal is a grant with a window: anything else leaves the
     * row where it is.
     */
    private function isFunding(Proposal $proposal): bool
    {
        return $proposal->status->isApprovedByDonor()
            && $proposal->status !== ProposalStatus::FinalReports
            && $proposal->actual_date_begin !== null
            && $proposal->effectiveDateEnd() !== null;
    }
}
