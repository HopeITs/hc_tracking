<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Enums\ProposalStatus;
use App\Enums\StatusNoteKind;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Take a proposal off hold (D-58, W-06): the donor came back, or the hold
 * was a mistake.
 *
 * The proposal has not moved, so nothing here writes a status either — the
 * flag and its reminder are cleared and one note records it. The second of
 * the two writers of `on_hold_since` (contracts.md §4).
 *
 * Clearing a proposal that is not on hold is a no-op that still writes its
 * note, so a double click costs an extra line in the timeline and nothing
 * else.
 */
final class ClearProposalOnHold
{
    public function handle(Proposal $proposal, ProposalStatus $expectedStatus, ?string $note = null): ProposalStatusNote
    {
        $written = DB::transaction(function () use ($proposal, $expectedStatus, $note): ProposalStatusNote {
            $locked = Proposal::query()->whereKey($proposal->getKey())->lockForUpdate()->firstOrFail();

            Gate::authorize('update', $locked);

            if ($locked->status !== $expectedStatus) {
                throw ValidationException::withMessages(['status' => __('proposals.stale_status')]);
            }

            $locked->on_hold_since = null;
            $locked->status_remind_on = null;
            $locked->save();

            $context = trim((string) $note);

            return ProposalStatusNote::query()->create([
                'proposal_id' => $locked->id,
                'status' => $locked->status,
                'kind' => StatusNoteKind::OffHold,
                'context' => $context !== '' ? $context : __('proposals.taken_off_hold_standard_note'),
                'date' => today()->toDateString(),
                'changed_by' => Auth::id(),
                'is_correction' => false,
            ]);
        });

        $proposal->refresh();

        return $written;
    }
}
