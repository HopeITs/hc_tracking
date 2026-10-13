<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Enums\ProposalStatus;
use App\Models\Proposal;
use App\Support\TransitionResult;
use LogicException;

/**
 * Correct a terminal proposal status with a mandatory reason.
 *
 * Stub with the frozen contracts.md §6 signature; T04 implements it.
 */
final class CorrectProposalStatus
{
    public function handle(Proposal $proposal, ProposalStatus $to, ProposalStatus $expectedFrom, string $reason): TransitionResult
    {
        throw new LogicException('Implemented in T04');
    }
}
