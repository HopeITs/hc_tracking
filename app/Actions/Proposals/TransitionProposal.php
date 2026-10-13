<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Enums\ProposalStatus;
use App\Models\Proposal;
use App\Support\TransitionResult;
use LogicException;

/**
 * Move a proposal along the contracts.md §2 transition table.
 *
 * Stub with the frozen contracts.md §6 signature; T04 implements it.
 */
final class TransitionProposal
{
    /**
     * @param  array{reason?: string, effective_date?: string, remind_on?: string}  $input
     */
    public function handle(Proposal $proposal, ProposalStatus $to, ProposalStatus $expectedFrom, array $input): TransitionResult
    {
        throw new LogicException('Implemented in T04');
    }
}
