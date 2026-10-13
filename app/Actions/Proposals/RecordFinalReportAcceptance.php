<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Models\Proposal;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * Record the donor's acceptance of the final report; the acceptor is the signed-in user.
 *
 * Stub with the frozen contracts.md §6 signature; T04 implements it.
 */
final class RecordFinalReportAcceptance
{
    public function handle(Proposal $proposal, CarbonImmutable $acceptedOn): Proposal
    {
        throw new LogicException('Implemented in T04');
    }
}
