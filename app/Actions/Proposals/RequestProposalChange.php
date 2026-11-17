<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Enums\ChangeReviewVerdict;
use App\Enums\ProposalChangeRequestStatus;
use App\Events\ProposalChangeRequested;
use App\Events\ProposalChangeReviewed;
use App\Models\Proposal;
use App\Models\ProposalChangeRequest;
use App\Models\User;
use App\Support\Reporting\ReportingCenters;
use App\Support\Slots;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Ask for a change to a proposal the donor has approved (contracts.md §6,
 * Y-09, D-89, D-89a).
 *
 * The data is validated as SaveProposal would (without the fill-only limit)
 * and its differences are stored as a `pending` request with one review row
 * per slot (`finance_manager`, `meal_manager`); a slot with no active holder
 * is born `skipped`. Nothing on the proposal changes: only an approval by
 * the PR Manager applies it. The activity names the changed fields only,
 * never values or amounts.
 */
final class RequestProposalChange
{
    /**
     * The keys of `$data` kept in `payload`: those SaveProposal accepts on
     * an approved proposal (contracts §7 "the validated input to apply").
     */
    private const PayloadKeys = [
        'name', 'donorId', 'plannedDateBegin', 'plannedDateEnd', 'notes', 'conceptNote', 'narrative',
        'requestedBudget', 'requestedCurrency', 'beneficiaries', 'locations',
    ];

    /**
     * @param  array<string, mixed>  $data  the keys SaveProposal accepts on an approved proposal, plus `locations`
     *
     * @throws ValidationException short reason, not approved, stale, already pending, nothing changes, invalid data
     */
    public function handle(Proposal $proposal, array $data, string $reason, ?string $expectedUpdatedAt = null): ProposalChangeRequest
    {
        Gate::authorize('requestChange', $proposal);

        /** @var User $actor */
        $actor = Auth::user();
        $reason = trim($reason);

        if (mb_strlen($reason) < 10) {
            throw ValidationException::withMessages(['reason' => __('proposals.change_request_reason_min')]);
        }

        $request = DB::transaction(function () use ($proposal, $data, $reason, $expectedUpdatedAt, $actor): ProposalChangeRequest {
            $locked = Proposal::query()->whereKey($proposal->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->isApprovedByDonor()) {
                throw ValidationException::withMessages(['status' => __('proposals.change_request_only_approved')]);
            }

            if ($expectedUpdatedAt !== null && $locked->updated_at?->toDateTimeString() !== $expectedUpdatedAt) {
                throw ValidationException::withMessages(['status' => __('proposals.stale_status')]);
            }

            if ($locked->changeRequests()->pending()->exists()) {
                throw ValidationException::withMessages(['changeRequest' => __('proposals.change_request_pending')]);
            }

            $data = array_intersect_key($data, array_flip(self::PayloadKeys));
            $changes = app(SaveProposal::class)->changesFor($locked, $data, $actor);

            if ($changes === []) {
                throw ValidationException::withMessages(['changeRequest' => __('proposals.change_request_empty')]);
            }

            app(ReportingCenters::class)->guard($locked, $changes);

            $request = ProposalChangeRequest::query()->create([
                'proposal_id' => $locked->id,
                'status' => ProposalChangeRequestStatus::Pending,
                'requested_by' => $actor->id,
                'reason' => $reason,
                'changes' => $changes,
                'payload' => $data,
            ]);

            foreach (ProposalChangeRequest::ReviewSlots as $slot) {
                $isVacant = Slots::holderOf($slot) === null;

                $request->reviews()->create([
                    'slot' => $slot,
                    'verdict' => $isVacant ? ChangeReviewVerdict::Skipped : null,
                    'reviewed_at' => $isVacant ? now() : null,
                ]);
            }

            $locked->recordActivity('proposal.change_requested', [], [
                'change_request_id' => $request->id,
                'fields' => $request->changedFields(),
            ]);

            // Both slots vacant: the request is ready for its decision at once, so say so (§15.2).
            if (! $request->reviews()->whereNull('verdict')->exists()) {
                $locked->recordActivity('proposal.change_reviewed', [], [
                    'change_request_id' => $request->id,
                    'ready' => true,
                    'remarks' => [],
                ]);
                ProposalChangeReviewed::dispatch($locked->id, $request->id, $actor->id);
            }

            return $request;
        });

        ProposalChangeRequested::dispatch($proposal->id, $request->id, $actor->id);

        return $request;
    }
}
