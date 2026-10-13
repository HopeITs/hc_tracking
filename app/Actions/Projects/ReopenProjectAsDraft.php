<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Models\Project;
use LogicException;

/**
 * Reopen a closed project as a draft; called inside SaveProposal/LinkProposalProject transactions.
 *
 * Stub with the frozen contracts.md §6 signature; T06 implements it.
 */
final class ReopenProjectAsDraft
{
    public function handle(Project $project): void
    {
        throw new LogicException('Implemented in T06');
    }
}
