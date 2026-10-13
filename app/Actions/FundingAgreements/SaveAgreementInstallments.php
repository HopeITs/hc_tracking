<?php

declare(strict_types=1);

namespace App\Actions\FundingAgreements;

use App\Models\DonorProposal;
use LogicException;

/**
 * Replace an agreement's installment schedule.
 *
 * Stub with the frozen contracts.md §6 signature; T09 implements it.
 */
final class SaveAgreementInstallments
{
    /**
     * @param  list<array{sequence: int, amount: string, currency: string, scheduled_date: string}>  $installments
     */
    public function handle(DonorProposal $agreement, array $installments): void
    {
        throw new LogicException('Implemented in T09');
    }
}
