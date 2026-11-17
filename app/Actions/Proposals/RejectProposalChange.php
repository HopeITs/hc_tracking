<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Enums\ProposalChangeRequestStatus;
use App\Events\ProposalChangeDecided;
use App\Models\Proposal;
use App\Models\ProposalChangeRequest;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Reject a pending change request with a note (contracts.md §6, Y-09,
 * D-89a, D-70). Only holders of `proposals.approve` decide; no review needs
 * to be in. The proposal is untouched.
 */
final class RejectProposalChange
{
    /**
     * @throws ValidationException short note, stale or decided request
     */
    public function handle(ProposalChangeRequest $request, string $note, string $expectedStatus = 'pending'): ProposalChangeRequest
    {
        /** @var Proposal $proposal */
        $proposal = $request->proposal()->firstOrFail();

        Gate::authorize('decideChange', $proposal);

        /** @var User $actor */
        $actor = Auth::user();
        $note = trim($note);

        if (mb_strlen($note) < 10) {
            throw ValidationException::withMessages(['note' => __('proposals.change_request_note_min')]);
        }

        $decided = DB::transaction(function () use ($proposal, $request, $note, $expectedStatus, $actor): ProposalChangeRequest {
            $lockedRequest = ProposalChangeRequest::query()
                ->whereKey($request->getKey())
                ->where('proposal_id', $proposal->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRequest->status->value !== $expectedStatus || ! $lockedRequest->isPending()) {
                throw ValidationException::withMessages(['changeRequest' => __('proposals.change_request_not_pending')]);
            }

            $lockedRequest->forceFill([
                'status' => ProposalChangeRequestStatus::Rejected,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ])->save();

            $proposal->recordActivity('proposal.change_rejected', properties: [
                'change_request_id' => $lockedRequest->id,
            ]);

            ProposalChangeDecided::dispatch($proposal->id, $lockedRequest->id, $actor->id, ProposalChangeRequestStatus::Rejected);

            return $lockedRequest;
        });

        $request->refresh();

        return $decided;
    }
}
