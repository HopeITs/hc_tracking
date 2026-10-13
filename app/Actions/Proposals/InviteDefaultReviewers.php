<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Enums\ReviewStage;
use App\Models\Proposal;
use LogicException;

/**
 * Invite the default reviewers of a review stage; called inside TransitionProposal's transaction.
 *
 * Stub with the frozen contracts.md §6 signature; T07 implements it.
 */
final class InviteDefaultReviewers
{
    public function handle(Proposal $proposal, ReviewStage $stage): void
    {
        throw new LogicException('Implemented in T07');
    }
}
