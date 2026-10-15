<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Models\Proposal;
use App\Notifications\EmployeeRecipients;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Take an extra reviewer off a proposal stage. Only an `additional` row that
 * has neither been seen nor carries a note may go; the rest is the review
 * record. The row loads through the proposal, so another proposal's id is a 404.
 *
 * Error key: `removeReviewer`.
 */
final class RemoveProposalReviewer
{
    public function __construct(private EmployeeRecipients $employees) {}

    public function handle(Proposal $proposal, int $reviewerId): void
    {
        DB::transaction(function () use ($proposal, $reviewerId): void {
            $locked = Proposal::query()->whereKey($proposal->getKey())->lockForUpdate()->firstOrFail();

            Gate::authorize('manageReviewers', $locked);

            $reviewer = $locked->reviewers()->whereKey($reviewerId)->lockForUpdate()->firstOrFail();

            if (! $reviewer->isRemovable()) {
                throw ValidationException::withMessages(['removeReviewer' => __('proposals.reviewer_cannot_be_removed')]);
            }

            $reviewer->delete();

            $locked->recordActivity('proposal.reviewer_removed', properties: [
                'stage' => $reviewer->stage->value,
                'employee_id' => $reviewer->employee_id,
                'employee' => $this->employees->names([$reviewer->employee_id])[$reviewer->employee_id] ?? null,
            ]);
        });
    }
}
