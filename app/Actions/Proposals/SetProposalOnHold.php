<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Enums\ProposalStatus;
use App\Enums\StatusNoteKind;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Put a proposal on hold while it waits on the donor (D-58, W-06).
 *
 * A hold is not a move: the proposal keeps its status and gains
 * `on_hold_since` plus the `status_remind_on` the reminder scanner reads.
 * Only `submitted` and `concept note submitted` can be held, because those
 * are the two statuses where the ball is in the donor's court.
 *
 * Like TransitionProposal, the row is reloaded under a lock and compared
 * with the status the caller last saw, so two people never both win, and the
 * flag and its one audit note commit together. This action is one of the two
 * writers of `on_hold_since` (contracts.md §4) and writes no status.
 */
final class SetProposalOnHold
{
    /**
     * The statuses a hold is allowed in (contracts.md §1).
     *
     * @var list<ProposalStatus>
     */
    private const HoldableStatuses = [ProposalStatus::Submitted, ProposalStatus::ConceptNoteSubmitted];

    /**
     * @param  array{reason: string, remind_on: string}  $input
     */
    public function handle(Proposal $proposal, ProposalStatus $expectedStatus, array $input): ProposalStatusNote
    {
        $note = DB::transaction(function () use ($proposal, $expectedStatus, $input): ProposalStatusNote {
            $locked = Proposal::query()->whereKey($proposal->getKey())->lockForUpdate()->firstOrFail();

            Gate::authorize('update', $locked);

            if ($locked->status !== $expectedStatus) {
                throw ValidationException::withMessages(['status' => __('proposals.stale_status')]);
            }

            if (! in_array($locked->status, self::HoldableStatuses, true)) {
                throw ValidationException::withMessages([
                    'status' => __('proposals.hold_only_while_submitted', ['status' => $locked->status->label()]),
                ]);
            }

            $validated = $this->validate($input);

            $locked->on_hold_since = CarbonImmutable::today();
            $locked->status_remind_on = CarbonImmutable::parse($validated['remind_on']);
            $locked->save();

            return ProposalStatusNote::query()->create([
                'proposal_id' => $locked->id,
                'status' => $locked->status,
                'kind' => StatusNoteKind::OnHold,
                'context' => $validated['reason'],
                'date' => today()->toDateString(),
                'changed_by' => Auth::id(),
                'is_correction' => false,
            ]);
        });

        $proposal->refresh();

        return $note;
    }

    /**
     * @param  array{reason: string, remind_on: string}  $input
     * @return array{reason: string, remind_on: string}
     */
    private function validate(array $input): array
    {
        /** @var array{reason: string, remind_on: string} $validated */
        $validated = Validator::make($input, [
            'reason' => ['required', 'string', 'max:5000'],
            'remind_on' => ['required', 'date_format:Y-m-d', 'after:today'],
        ], [
            'reason.required' => __('proposals.reason_required'),
            'remind_on.required' => __('proposals.remind_on_future'),
            'remind_on.date_format' => __('proposals.remind_on_future'),
            'remind_on.after' => __('proposals.remind_on_future'),
        ])->validate();

        return $validated;
    }
}
