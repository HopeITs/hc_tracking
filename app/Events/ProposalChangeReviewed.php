<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The last waiting review of a change request came in (contracts.md §6,
 * Y-09, D-89a): the PR Manager may now decide. Raised after commit, once.
 */
final class ProposalChangeReviewed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $proposalId,
        public readonly int $changeRequestId,
        public readonly int $actorId,
    ) {}
}
