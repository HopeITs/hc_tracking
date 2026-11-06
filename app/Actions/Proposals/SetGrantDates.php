<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Models\Proposal;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Fill in the missing actual grant dates of a donor-approved proposal.
 *
 * Approval normally records them (ApproveProposal), but older proposals were
 * approved without them, and no page could add them afterwards, so donor
 * reports refused every period (page review, 6 Nov 2026). This only fills
 * what is missing: an actual start that exists is kept, and a grant that
 * already has both dates is refused (a later end goes through ExtendProposal).
 * Linked projects and the plan are not touched.
 *
 * - Who: `update` on the proposal.
 * - The end is on or after the start.
 * - Logged as `proposal.grant_dates_set`.
 */
final class SetGrantDates
{
    /**
     * @param  array{begin?: ?string, end?: ?string}  $input
     *
     * @throws ValidationException grantDateBegin, grantDateEnd
     */
    public function handle(Proposal $proposal, array $input, User $actor): Proposal
    {
        Gate::forUser($actor)->authorize('update', $proposal);

        if (! $proposal->status->isApprovedByDonor() || ! $proposal->lacksGrantDates()) {
            throw ValidationException::withMessages(['grantDateBegin' => __('proposals.grant_dates_not_missing')]);
        }

        $begin = $proposal->actual_date_begin?->toDateString() ?? ($input['begin'] ?? null);

        $validated = Validator::make(
            ['grantDateBegin' => $begin, 'grantDateEnd' => $input['end'] ?? null],
            [
                'grantDateBegin' => ['required', 'date_format:Y-m-d'],
                'grantDateEnd' => ['required', 'date_format:Y-m-d', 'after_or_equal:grantDateBegin'],
            ],
            [
                'grantDateBegin.required' => __('proposals.grant_dates_begin_required'),
                'grantDateEnd.required' => __('proposals.grant_dates_end_required'),
                'grantDateEnd.after_or_equal' => __('proposals.grant_end_before_start'),
            ],
        )->validate();

        $proposal->forceFill([
            'actual_date_begin' => $validated['grantDateBegin'],
            'actual_date_end' => $validated['grantDateEnd'],
        ])->save();

        $proposal->recordActivity('proposal.grant_dates_set');

        return $proposal;
    }
}
