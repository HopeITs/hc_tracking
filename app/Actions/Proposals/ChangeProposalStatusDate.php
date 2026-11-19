<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Enums\ProposalStatus;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Correct the Sent date or the Approved date of a proposal: the date of its
 * latest move to Submitted or to Approved.
 *
 * Only the date of that status note changes; the status does not move. The
 * date may not be in the future, and the Approved date may not fall before
 * the Sent date. The change is recorded on the proposal as
 * `proposal.status_date_changed` with the old and new date.
 */
final class ChangeProposalStatusDate
{
    /** The statuses whose date the proposal card shows. */
    public const STATUSES = [ProposalStatus::Submitted, ProposalStatus::Approved];

    /**
     * @throws ValidationException
     */
    public function handle(Proposal $proposal, ProposalStatus $status, CarbonImmutable $date): ProposalStatusNote
    {
        Gate::authorize('update', $proposal);

        $field = $status === ProposalStatus::Submitted ? 'sent' : 'approved';

        if (! in_array($status, self::STATUSES, true)) {
            throw ValidationException::withMessages([$field => __('proposals.page.date_not_editable')]);
        }

        if ($date->startOfDay()->isAfter(today())) {
            throw ValidationException::withMessages([$field => __('proposals.date_not_future')]);
        }

        return DB::transaction(function () use ($proposal, $status, $date, $field): ProposalStatusNote {
            $locked = Proposal::query()->whereKey($proposal->getKey())->lockForUpdate()->firstOrFail();

            $note = self::latestNote($locked->id, $status);

            if ($note === null) {
                throw ValidationException::withMessages([$field => $status === ProposalStatus::Submitted ? __('proposals.page.not_sent_yet') : __('proposals.page.not_approved_yet')]);
            }

            $other = self::latestNote($locked->id, $status === ProposalStatus::Submitted ? ProposalStatus::Approved : ProposalStatus::Submitted);

            if ($other !== null && $other->date !== null) {
                $sent = $status === ProposalStatus::Submitted ? $date : $other->date;
                $approved = $status === ProposalStatus::Approved ? $date : $other->date;

                if ($approved->lt($sent)) {
                    throw ValidationException::withMessages([$field => __('proposals.page.approved_before_sent')]);
                }
            }

            $from = $note->date?->toDateString();
            $note->date = $date->startOfDay();
            $note->save();

            $locked->recordActivity('proposal.status_date_changed', ['date' => [$from, $date->toDateString()]], ['status' => $status->value]);

            return $note;
        });
    }

    /**
     * The latest note that moved the proposal to the status, or null.
     */
    public static function latestNote(int $proposalId, ProposalStatus $status): ?ProposalStatusNote
    {
        return ProposalStatusNote::query()
            ->where('proposal_id', $proposalId)
            ->where('status', $status->value)
            ->where('is_correction', false)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->first();
    }
}
