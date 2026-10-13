<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\ProposalStatus;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A proposal moved from one status to another (contracts.md §5). Raised once
 * per move by every status writer, and only after the move commits.
 */
final class ProposalStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $proposalId,
        public readonly ProposalStatus $from,
        public readonly ProposalStatus $to,
        public readonly ?int $actorId,
        public readonly bool $isCorrection = false,
    ) {}
}
