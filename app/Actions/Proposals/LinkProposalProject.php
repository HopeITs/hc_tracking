<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Models\Proposal;
use LogicException;

/**
 * Link a project to a proposal.
 *
 * Stub with the frozen contracts.md §6 signature; T06 implements it.
 */
final class LinkProposalProject
{
    public function handle(Proposal $proposal, int $projectId, ?int $targetBeneficiaries = null): void
    {
        throw new LogicException('Implemented in T06');
    }
}
