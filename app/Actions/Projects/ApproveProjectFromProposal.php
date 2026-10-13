<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Models\Project;
use LogicException;

/**
 * Approve a draft project once its proposal is approved and its owner has acknowledged; idempotent.
 *
 * Stub with the frozen contracts.md §6 signature; T08 implements it.
 */
final class ApproveProjectFromProposal
{
    /**
     * @param  string  $via  proposal_approval or owner_acknowledgement
     */
    public function handle(Project $project, string $via): bool
    {
        throw new LogicException('Implemented in T08');
    }
}
