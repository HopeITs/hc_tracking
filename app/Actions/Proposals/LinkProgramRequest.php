<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Actions\Projects\ApproveProjectFromProposal;
use App\Enums\ProposalStatus;
use App\Events\ProposalLocationsChanged;
use App\Models\Program;
use App\Models\ProgramRequest;
use App\Models\Project;
use App\Models\Proposal;
use App\Support\MaterialisationResult;
use App\Support\ProposalLocationRows;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Link a pending program request to the HR program created for it
 * (contracts.md §7, W-34a slice 3), from `/admin/program-requests`.
 *
 * One transaction under the proposal lock: the request is marked created
 * with `hr_program_id`, its location row takes the program (through
 * ProposalLocationRows), and `program_request.linked` is recorded on the
 * proposal. A proposal the donor already approved (approved or active) no
 * longer waits at its own approval, so the projects the row now needs are
 * materialised here (MaterialiseProposalLocations) and each one is given
 * the grant dates and approved like at the approval
 * (ApproveProjectFromProposal). Any refusal there undoes the link.
 * ProposalLocationsChanged fires after the commit.
 */
final class LinkProgramRequest
{
    /** @var list<ProposalStatus> */
    private const MaterialisingStatuses = [ProposalStatus::Approved, ProposalStatus::Active];

    public function __construct(
        private ProposalLocationRows $rows,
        private MaterialiseProposalLocations $materialiseLocations,
        private ApproveProjectFromProposal $approveProject,
    ) {}

    /**
     * Returns what materialising did, or null when the proposal still waits
     * for its approval.
     *
     * @throws ValidationException under `programId` or `request`
     */
    public function handle(ProgramRequest $request, int $programId): ?MaterialisationResult
    {
        Gate::authorize('update', $request);

        $proposalId = (int) DB::table('proposal_locations')->where('id', $request->proposal_location_id)->value('proposal_id');

        $result = DB::transaction(function () use ($request, $proposalId, $programId): ?MaterialisationResult {
            $locked = Proposal::query()->whereKey($proposalId)->lockForUpdate()->firstOrFail();
            $current = ProgramRequest::query()->whereKey($request->getKey())->lockForUpdate()->first();

            if ($current === null || ! $current->isPending()) {
                throw ValidationException::withMessages(['request' => __('program_requests.no_longer_pending')]);
            }

            if (! Program::query()->whereKey($programId)->exists()) {
                throw ValidationException::withMessages(['programId' => __('program_requests.choose_program')]);
            }

            $counts = $this->rows->linkRequestedProgram($locked, $current, $programId);

            $locked->recordActivity('program_request.linked', properties: [
                'program_request_id' => $current->id,
                'proposal_location_id' => $current->proposal_location_id,
                'hr_program_id' => $programId,
                'name' => $current->name_en,
            ]);

            ProposalLocationsChanged::dispatch($locked->id, Auth::id(), $counts['added'], $counts['removed'], $counts['changed']);

            if (! in_array($locked->status, self::MaterialisingStatuses, true)) {
                return null;
            }

            $materialised = $this->materialiseLocations->handle($locked, (int) Auth::id());

            foreach ([...$materialised->createdProjectIds, ...$materialised->linkedProjectIds] as $projectId) {
                $this->approveProject->handle(Project::query()->findOrFail($projectId), 'proposal_approval');
            }

            return $materialised;
        });

        $request->refresh();

        return $result;
    }
}
