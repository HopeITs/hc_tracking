<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\ProposalChangeRequestStatus;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A change request was approved, rejected or withdrawn (contracts.md §6,
 * Y-09, D-89a). Raised after commit; carries ids and the outcome only.
 */
final class ProposalChangeDecided implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $proposalId,
        public readonly int $changeRequestId,
        public readonly int $actorId,
        public readonly ProposalChangeRequestStatus $status,
    ) {}
}
