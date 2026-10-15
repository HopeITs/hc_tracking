<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\FundingAgreements\GenerateExpectedTransfers;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Generates the expected transfers of one agreement after its schedule was
 * saved. It carries only the id: the action re-reads everything.
 */
final class GenerateExpectedTransfersJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $agreementId) {}

    public function handle(GenerateExpectedTransfers $generate): void
    {
        $generate->handle($this->agreementId);
    }
}
