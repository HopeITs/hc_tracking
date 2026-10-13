<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Models\Proposal;
use LogicException;

/**
 * Unlink a project from a proposal.
 *
 * Stub with the frozen contracts.md §6 signature; T06 implements it.
 */
final class UnlinkProposalProject
{
    public function handle(Proposal $proposal, int $projectId): void
    {
        throw new LogicException('Implemented in T06');
    }
}
