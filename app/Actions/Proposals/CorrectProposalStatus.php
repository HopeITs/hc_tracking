<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Enums\ProposalStatus;
use App\Events\ProposalStatusChanged;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use App\Support\ProposalTransitions;
use App\Support\TransitionResult;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Correct a terminal proposal status with a mandatory reason (D-20).
 *
 * Only rejected or completed proposals are corrected, to any other live
 * status except approved (approval has its own evidence, T08). The note is
 * flagged `is_correction` and the event carries the same flag.
 */
final class CorrectProposalStatus
{
    public function handle(Proposal $proposal, ProposalStatus $to, ProposalStatus $expectedFrom, string $reason): TransitionResult
    {
        $result = DB::transaction(function () use ($proposal, $to, $expectedFrom, $reason): TransitionResult {
            $locked = Proposal::query()->whereKey($proposal->getKey())->lockForUpdate()->firstOrFail();

            Gate::authorize('correctStatus', $locked);

            $from = $locked->status;

            if ($from !== $expectedFrom) {
                throw ValidationException::withMessages(['status' => __('proposals.stale_status')]);
            }

            if (! in_array($to, ProposalTransitions::correctionTargets($from), true)) {
                throw ValidationException::withMessages([
                    'status' => __('proposals.transition_not_allowed', ['from' => $from->label(), 'to' => $to->label()]),
                ]);
            }

            $reason = trim($reason);

            if ($reason === '') {
                throw ValidationException::withMessages(['reason' => __('proposals.reason_required')]);
            }

            $locked->status = $to;
            $locked->status_remind_on = null;
            $locked->save();

            $note = ProposalStatusNote::query()->create([
                'proposal_id' => $locked->id,
                'status' => $to,
                'context' => $reason,
                'date' => today()->toDateString(),
                'changed_by' => Auth::id(),
                'is_correction' => true,
            ]);

            ProposalStatusChanged::dispatch($locked->id, $from, $to, Auth::id(), true);

            return new TransitionResult($note, []);
        });

        $proposal->refresh();

        return $result;
    }
}
