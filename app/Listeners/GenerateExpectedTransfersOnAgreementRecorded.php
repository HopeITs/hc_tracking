<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\FundingAgreements\GenerateExpectedTransfers;
use App\Events\FundingAgreementRecorded;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Generates the expected transfers when an agreement is recorded
 * (contracts.md §5). Queued and idempotent, and never calls back into Phase 1
 * actions.
 */
final class GenerateExpectedTransfersOnAgreementRecorded implements ShouldQueue
{
    public int $tries = 3;

    public function __construct(private readonly GenerateExpectedTransfers $generate) {}

    public function handle(FundingAgreementRecorded $event): void
    {
        $this->generate->handle($event->fundingAgreementId);
    }
}
