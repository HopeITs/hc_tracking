<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Enums\ReviewerSlot;
use App\Enums\ReviewStage;
use App\Models\HrEmployee;
use App\Models\Proposal;
use App\Models\ProposalReviewer;
use App\Notifications\EmployeeRecipients;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Add an extra reviewer to the stage the proposal is in now: its concept
 * note (draft or review) or its proposal (draft or review). The stage is
 * derived from the locked row, never taken from the caller.
 *
 * Error key: `reviewerEmployeeId`.
 */
final class AddProposalReviewer
{
    public function __construct(private EmployeeRecipients $employees) {}

    public function handle(Proposal $proposal, int $employeeId): ProposalReviewer
    {
        try {
            return DB::transaction(function () use ($proposal, $employeeId): ProposalReviewer {
                $locked = Proposal::query()->whereKey($proposal->getKey())->lockForUpdate()->firstOrFail();

                Gate::authorize('manageReviewers', $locked);

                $stage = ReviewStage::forAddingIn($locked->status);

                if ($stage === null) {
                    throw ValidationException::withMessages(['reviewerEmployeeId' => __('proposals.reviewers_closed_for_status')]);
                }

                if (! HrEmployee::query()->whereKey($employeeId)->exists()) {
                    throw ValidationException::withMessages(['reviewerEmployeeId' => __('proposals.choose_a_reviewer')]);
                }

                if ($locked->reviewers()->forStage($stage)->where('employee_id', $employeeId)->exists()) {
                    throw ValidationException::withMessages(['reviewerEmployeeId' => __('proposals.reviewer_already_added')]);
                }

                $reviewer = ProposalReviewer::query()->create([
                    'proposal_id' => $locked->id,
                    'stage' => $stage,
                    'employee_id' => $employeeId,
                    'slot' => ReviewerSlot::Additional,
                    'added_by' => Auth::id(),
                ]);

                $locked->recordActivity('proposal.reviewer_added', properties: [
                    'stage' => $stage->value,
                    'employee_id' => $employeeId,
                    'employee' => $this->employees->names([$employeeId])[$employeeId] ?? null,
                ]);

                return $reviewer;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains($exception->getMessage(), ProposalReviewer::UNIQUE_INDEX)) {
                throw $exception;
            }

            throw ValidationException::withMessages(['reviewerEmployeeId' => __('proposals.reviewer_already_added')]);
        }
    }
}
