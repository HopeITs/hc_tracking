<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Models\Proposal;
use App\Models\ProposalReviewer;
use App\Notifications\ActivityRecorder;
use App\Notifications\EmployeeRecipients;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A reviewer marks their own row as seen, optionally with a note, or edits
 * that note later. `seen_at` is set once: marking again changes nothing. The
 * note may change only while the proposal is in that stage's review status.
 * The row loads through the proposal, so another proposal's id is a 404.
 *
 * Error key: `note`.
 */
final class MarkProposalReviewed
{
    public function __construct(private EmployeeRecipients $employees) {}

    /**
     * @param  string|null  $note  the note the row should carry; blank means none
     */
    public function handle(Proposal $proposal, int $reviewerId, ?string $note): ProposalReviewer
    {
        $note = trim((string) $note);
        $note = $note === '' ? null : $note;

        Validator::make(['note' => $note], ['note' => ['nullable', 'string', 'max:2000']])->validate();

        return DB::transaction(function () use ($proposal, $reviewerId, $note): ProposalReviewer {
            $locked = Proposal::query()->whereKey($proposal->getKey())->lockForUpdate()->firstOrFail();
            $reviewer = $locked->reviewers()->whereKey($reviewerId)->lockForUpdate()->firstOrFail();

            Gate::authorize('markReviewed', [$locked, $reviewer]);

            $isFirstSeen = $reviewer->seen_at === null;
            $noteChanged = $note !== $reviewer->note;

            if ($noteChanged && $locked->status !== $reviewer->stage->reviewStatus()) {
                throw ValidationException::withMessages(['note' => __('proposals.reviewer_note_stage_closed')]);
            }

            if (! $isFirstSeen && ! $noteChanged) {
                return $reviewer;
            }

            $reviewer->seen_at ??= now()->toImmutable();
            $reviewer->note = $note;
            $reviewer->save();

            $properties = [
                'stage' => $reviewer->stage->value,
                'employee_id' => $reviewer->employee_id,
                'employee' => $this->employees->names([$reviewer->employee_id])[$reviewer->employee_id] ?? null,
            ];

            if ($isFirstSeen) {
                $locked->recordActivity('proposal.reviewer_seen', properties: $properties);
            } else {
                app(ActivityRecorder::class)->record($locked, 'proposal.reviewer_note_updated', properties: $properties, notify: false);
            }

            return $reviewer;
        });
    }
}
