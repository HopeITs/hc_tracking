<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A change request was opened on an approved proposal (contracts.md §6,
 * Y-09, D-89). Raised after commit; carries ids only.
 */
final class ProposalChangeRequested implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $proposalId,
        public readonly int $changeRequestId,
        public readonly int $actorId,
    ) {}
}
