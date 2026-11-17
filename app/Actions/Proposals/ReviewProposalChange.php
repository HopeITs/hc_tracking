<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Enums\ChangeReviewVerdict;
use App\Events\ProposalChangeReviewed;
use App\Models\Proposal;
use App\Models\ProposalChangeRequest;
use App\Models\ProposalChangeRequestReview;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Record one slot holder's review of a change request (contracts.md §6,
 * Y-09, D-89a).
 *
 * Advice only: it never approves, rejects or applies anything. The holder of
 * `$slot` gives `no_objection` or `remarks` (with a note) and may change it
 * until the request is decided. A slot that has no holder any more is
 * marked `skipped` first, so it never blocks. When this review takes the
 * request from "a review is waiting" to "none waiting",
 * ProposalChangeReviewed is raised once, after commit.
 */
final class ReviewProposalChange
{
    /**
     * @throws ValidationException bad verdict, short note, request already decided
     */
    public function handle(ProposalChangeRequest $request, string $slot, string $verdict, ?string $note = null): ProposalChangeRequestReview
    {
        /** @var Proposal $proposal */
        $proposal = $request->proposal()->firstOrFail();

        Gate::authorize('reviewChange', [$proposal, $slot]);

        $chosen = ChangeReviewVerdict::tryFrom($verdict);

        if ($chosen === null || $chosen === ChangeReviewVerdict::Skipped) {
            throw ValidationException::withMessages(['verdict' => __('proposals.change_request_verdict_invalid')]);
        }

        $note = $note === null ? null : trim($note);
        $note = $note === '' ? null : $note;

        if ($chosen === ChangeReviewVerdict::Remarks && mb_strlen((string) $note) < 10) {
            throw ValidationException::withMessages(['note' => __('proposals.change_request_note_min')]);
        }

        $actorId = (int) Auth::id();

        return DB::transaction(function () use ($request, $proposal, $slot, $chosen, $note, $actorId): ProposalChangeRequestReview {
            $locked = ProposalChangeRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                throw ValidationException::withMessages(['changeRequest' => __('proposals.change_request_not_pending')]);
            }

            $review = $locked->reviews()->where('slot', $slot)->firstOrFail();
            $waitingBefore = $locked->reviews()->whereNull('verdict')->count();

            $review->forceFill([
                'verdict' => $chosen,
                'note' => $note,
                'reviewed_by' => $actorId,
                'reviewed_at' => now(),
            ])->save();

            $locked->skipVacantReviews();

            $isReady = $waitingBefore > 0 && $locked->reviews()->whereNull('verdict')->doesntExist();

            /** @var array{change_request_id: int, slot: string, verdict: string, ready: bool, remarks?: list<string>} $properties */
            $properties = [
                'change_request_id' => $locked->id,
                'slot' => $slot,
                'verdict' => $chosen->value,
                'ready' => $isReady,
            ];

            if ($isReady) {
                $properties['remarks'] = array_values($locked->reviews()
                    ->where('verdict', ChangeReviewVerdict::Remarks->value)
                    ->orderBy('slot')
                    ->pluck('slot')
                    ->all());
            }

            $proposal->recordActivity('proposal.change_reviewed', [], $properties);

            if ($isReady) {
                ProposalChangeReviewed::dispatch($proposal->id, $locked->id, $actorId);
            }

            return $review;
        });
    }
}
