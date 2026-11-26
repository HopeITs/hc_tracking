<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Actions\Locations\ApproveLocationFromProposal;
use App\Enums\ProjectStatus;
use App\Models\HrCity;
use App\Models\Program;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Support\MaterialisationResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Complete every location row of an approved proposal (contracts.md §6,
 * W-34a slice 2, D-62, Y-17). ApproveProposal calls it inside its own
 * transaction, after the proposal row is locked.
 *
 * Y-17: nothing is created and nothing is linked. A place IS a row (D-93),
 * so approving the proposal simply approves its rows through
 * ApproveLocationFromProposal — which dates each row from this proposal's
 * grant and moves it to `approved` once its responsible has acknowledged.
 *
 * What the result reports, and why each list exists:
 * - **approved**: the row moved on this call.
 * - **waiting for its owner**: the row is dated and ready, and the
 *   responsible has not answered yet. They approve it themselves by
 *   acknowledging (`ProposalLocation::assignmentAcknowledged()`).
 * - **waiting for its program**: the row names no program. Its pending
 *   request already blocks the approval through `locations_complete`, so
 *   here it is only reported.
 *
 * A row whose responsible has rejected the appointment is skipped and stays
 * locked (ASG-03); ApproveProposal reports it under `skipped`. A row that is
 * already approved or active is left alone, so a second run writes nothing.
 */
final class MaterialiseProposalLocations
{
    public function handle(Proposal $proposal, int $actorId): MaterialisationResult
    {
        $result = DB::transaction(function () use ($proposal): MaterialisationResult {
            $locked = Proposal::query()->whereKey($proposal->getKey())->lockForUpdate()->firstOrFail();

            Gate::authorize('approve', $locked);

            $approved = [];
            $waitingForOwner = [];
            $waitingForProgram = [];

            foreach ($this->rows($locked) as $row) {
                if ($row->program_id === null) {
                    $waitingForProgram[] = $row->id;

                    continue;
                }

                $this->assertOwnable($row);

                if (app(ApproveLocationFromProposal::class)->handle($row, 'proposal_approval')) {
                    $approved[] = $row->id;

                    continue;
                }

                if ($row->fresh()->status === ProjectStatus::Draft && ! $row->isOwnerRejected()) {
                    $waitingForOwner[] = $row->id;
                }
            }

            return new MaterialisationResult($approved, $waitingForOwner, $waitingForProgram, []);
        });

        $proposal->unsetRelation('locations');

        return $result;
    }

    /**
     * What handle() would do, for the approve modal: the rows that would be
     * approved, those waiting for their responsible and those waiting for a
     * program. Reads only.
     */
    public function preview(Proposal $proposal): MaterialisationResult
    {
        Gate::authorize('view', $proposal);

        $approved = [];
        $waitingForOwner = [];
        $waitingForProgram = [];

        foreach ($this->rows($proposal) as $row) {
            if ($row->program_id === null) {
                $waitingForProgram[] = $row->id;

                continue;
            }

            if ($row->isOwnerRejected()) {
                continue;
            }

            $row->isAssignmentAcknowledged('responsible_employee_id')
                ? $approved[] = $row->id
                : $waitingForOwner[] = $row->id;
        }

        return new MaterialisationResult($approved, $waitingForOwner, $waitingForProgram, []);
    }

    /**
     * The proposal's rows still to complete, in the order the form shows
     * them, with what the checks below read already loaded.
     *
     * @return list<ProposalLocation>
     */
    private function rows(Proposal $proposal): array
    {
        return array_values(
            $proposal->locations()
                ->with(['centers', 'ownerAssignment'])
                ->orderBy('sort')
                ->orderBy('id')
                ->get()
                ->all()
        );
    }

    /**
     * Somebody must be able to answer for the row: its responsible, or the
     * manager of its program. With neither, the approval is refused before
     * anything is written — the same gate the project's owner check was.
     *
     * @throws ValidationException under `locations_complete`
     */
    private function assertOwnable(ProposalLocation $row): void
    {
        if ($row->responsible_employee_id !== null) {
            return;
        }

        if (Program::query()->whereKey($row->program_id)->whereNotNull('manager_id')->exists()) {
            return;
        }

        throw ValidationException::withMessages([
            'locations_complete' => __('proposals.location_owner_required', [
                'city' => HrCity::query()->find($row->city_id)?->name ?? '#'.$row->city_id,
            ]),
        ]);
    }
}
