<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Enums\ProposalStatus;
use App\Models\Proposal;
use App\Support\ApprovalResult;
use LogicException;

/**
 * Approve a proposal on the donor's decision.
 *
 * Stub with the frozen contracts.md §6 signature; T08 implements it.
 */
final class ApproveProposal
{
    /**
     * @param  array{actual_date_begin: string, actual_date_end: string, decision_date: string, note?: ?string, approval_note?: ?string}  $input
     */
    public function handle(Proposal $proposal, ProposalStatus $expectedFrom, array $input): ApprovalResult
    {
        throw new LogicException('Implemented in T08');
    }
}
