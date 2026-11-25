<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Enums\ProgramRequestStatus;
use App\Models\ProgramRequest;
use App\Models\Proposal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Decline a pending program request (contracts.md §7, W-34a slice 3), from
 * `/admin/program-requests`, with a reason recorded on the proposal as
 * `program_request.declined`.
 *
 * The location row is left as it is: it still has no program, so the
 * proposal stays blocked at `locations_complete` until its editor picks an
 * existing program or removes the row.
 */
final class DeclineProgramRequest
{
    /**
     * @throws ValidationException under `declineReason` or `request`
     */
    public function handle(ProgramRequest $request, string $reason): void
    {
        Gate::authorize('update', $request);

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['declineReason' => __('program_requests.decline_reason_required')]);
        }

        $proposalId = (int) DB::table('proposal_locations')->where('id', $request->proposal_location_id)->value('proposal_id');

        DB::transaction(function () use ($request, $proposalId, $reason): void {
            $locked = Proposal::query()->whereKey($proposalId)->lockForUpdate()->firstOrFail();
            $current = ProgramRequest::query()->whereKey($request->getKey())->lockForUpdate()->first();

            if ($current === null || ! $current->isPending()) {
                throw ValidationException::withMessages(['request' => __('program_requests.no_longer_pending')]);
            }

            $current->forceFill(['status' => ProgramRequestStatus::Declined])->save();

            $locked->recordActivity('program_request.declined', properties: [
                'program_request_id' => $current->id,
                'proposal_location_id' => $current->proposal_location_id,
                'name' => $current->name_en,
                'reason' => $reason,
            ]);
        });

        $request->refresh();
    }
}
