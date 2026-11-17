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
 * The requester withdraws their own pending change request (contracts.md
 * §6, Y-09, D-89a). Nothing is deleted: the request is marked `rejected`
 * with the "withdrawn by the requester" note, the proposal is untouched,
 * and `proposal.change_rejected` is recorded with `withdrawn: true`.
 */
final class WithdrawProposalChange
{
    /**
     * @throws ValidationException the request is no longer pending, or not in the expected status
     */
    public function handle(ProposalChangeRequest $request, string $expectedStatus = 'pending'): ProposalChangeRequest
    {
        /** @var Proposal $proposal */
        $proposal = $request->proposal()->firstOrFail();

        Gate::authorize('withdrawChange', [$proposal, $request]);

        /** @var User $actor */
        $actor = Auth::user();

        $withdrawn = DB::transaction(function () use ($request, $proposal, $expectedStatus, $actor): ProposalChangeRequest {
            $locked = ProposalChangeRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status->value !== $expectedStatus || ! $locked->isPending()) {
                throw ValidationException::withMessages(['changeRequest' => __('proposals.change_request_not_pending')]);
            }

            $locked->forceFill([
                'status' => ProposalChangeRequestStatus::Rejected,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_note' => __('proposals.change_request_withdrawn_note'),
            ])->save();

            $proposal->recordActivity('proposal.change_rejected', [], [
                'change_request_id' => $locked->id,
                'withdrawn' => true,
            ]);

            return $locked;
        });

        ProposalChangeDecided::dispatch($proposal->id, $withdrawn->id, $actor->id, ProposalChangeRequestStatus::Rejected);

        return $withdrawn;
    }
}
