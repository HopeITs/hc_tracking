<?php

declare(strict_types=1);

namespace App\Actions\FundingAgreements;

use App\Models\Activity;
use App\Models\DonorProposal;
use App\Models\TransfersTracking;
use Illuminate\Support\Facades\DB;

/**
 * Create one expected transfer per installment that has none (contracts.md
 * §6, D-31). Runs as the system, from the queue: it takes the agreement id,
 * re-reads every row and trusts nothing the caller knew.
 *
 * It is idempotent per installment (the unique `installment_id` is the
 * guarantee; the agreement row is locked so two workers cannot both insert),
 * and it generates nothing for a proposal no donor approved or for a
 * self-funded one. The actuals (sent, arrival and received dates, received
 * amount, commission, exchange rate) stay empty for Finance.
 */
final class GenerateExpectedTransfers
{
    /**
     * @return int the number of transfers created by this run
     */
    public function handle(int $agreementId): int
    {
        return DB::transaction(function () use ($agreementId): int {
            $agreement = DonorProposal::query()->lockForUpdate()->find($agreementId);

            if ($agreement === null) {
                return 0;
            }

            $agreement->load(['proposal:id,status', 'donor:id,is_internal']);

            if (! $agreement->proposal->status->isApprovedByDonor() || $agreement->donor->is_internal) {
                return 0;
            }

            $created = Activity::withoutRecording(function () use ($agreement): array {
                $created = [];

                foreach ($agreement->installments()->whereDoesntHave('transfer')->get() as $installment) {
                    $created[] = TransfersTracking::query()->create([
                        'donor_proposal_id' => $agreement->id,
                        'payment_amount' => $installment->amount,
                        'amount_transfered_currency' => $installment->currency,
                        'fees_currency' => $installment->currency,
                        'amount_received_currency' => $installment->currency,
                        'scheduled_date' => $installment->scheduled_date,
                        'source' => 'auto',
                        'installment_id' => $installment->id,
                    ]);
                }

                return $created;
            });

            if ($created !== []) {
                // One movement per run, carrying no amount (contracts.md §15).
                $created[0]->recordActivity('transfer.generated', properties: ['count' => count($created)]);
            }

            return count($created);
        });
    }
}
