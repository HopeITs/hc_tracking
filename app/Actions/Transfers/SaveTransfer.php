<?php

declare(strict_types=1);

namespace App\Actions\Transfers;

use App\Models\TransfersTracking;
use Illuminate\Support\Facades\Gate;

/**
 * Record a new tranche or update an existing one from already-validated input.
 */
class SaveTransfer
{
    /**
     * @param  array<string, mixed>  $validated  keyed by the transfer form's property names
     */
    public function handle(?TransfersTracking $existing, array $validated): TransfersTracking
    {
        Gate::authorize($existing === null ? 'transfers.create' : 'transfers.update');

        $transfer = $existing ?? new TransfersTracking;

        $transfer->fill([
            'donor_proposal_id' => (int) $validated['transferAgreementId'],
            'account_id' => $validated['transferAccountId'] === '' || $validated['transferAccountId'] === null
                ? null
                : (int) $validated['transferAccountId'],
            'payment_amount' => $validated['paymentAmount'],
            'amount_transfered_currency' => $validated['transferredCurrency'],
            'scheduled_date' => $validated['scheduledDate'] === '' ? null : $validated['scheduledDate'],
            'transfer_date' => $validated['transferDate'] === '' ? null : $validated['transferDate'],
            'fees' => $validated['fees'],
            'fees_currency' => $validated['feesCurrency'],
            'amount_received' => $validated['amountReceived'] === '' ? null : $validated['amountReceived'],
            'exchange_rate' => $validated['exchangeRate'],
            'net_amount_received' => $validated['netAmountReceived'] === '' ? null : $validated['netAmountReceived'],
            'amount_received_currency' => $validated['receivedCurrency'],
            'receive_date' => $validated['receiveDate'] === '' ? null : $validated['receiveDate'],
            'ar_status' => $validated['transferArStatus'],
        ])->save();

        return $transfer;
    }
}
