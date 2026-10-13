<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A funding agreement was created (contracts.md §5). `$fundingAgreementId` is
 * the `donor_proposal` row id.
 */
final class FundingAgreementRecorded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $fundingAgreementId,
        public readonly int $proposalId,
        public readonly ?int $actorId,
    ) {}
}
