<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Schedule a tranche against a funding agreement, and return its id.
 */
function insertTransfer(int $donorProposalId, ?int $accountId = null, array $overrides = []): int
{
    return DB::table('transfers_tracking')->insertGetId(array_merge([
        'donor_proposal_id' => $donorProposalId,
        'account_id' => $accountId,
        'payment_amount' => '250000.00',
        'scheduled_date' => '2026-04-01',
        'amount_received' => '0.00',
        'net_amount_received' => '0.00',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

/**
 * Insert a funding agreement and return its id.
 */
function insertAgreement(): int
{
    return insertDonorProposal(insertDonor(), insertProposal());
}

test('a scheduled tranche starts pending, in USD, at par and free of fees', function () {
    $transfer = DB::table('transfers_tracking')->find(insertTransfer(insertAgreement()));

    expect($transfer->ar_status)->toBe('pending')
        ->and($transfer->amount_transfered_currency)->toBe('USD')
        ->and($transfer->fees_currency)->toBe('USD')
        ->and($transfer->amount_received_currency)->toBe('USD')
        ->and($transfer->fees)->toBe('0.00')
        ->and($transfer->exchange_rate)->toBe('1.000000')
        ->and($transfer->transfer_date)->toBeNull()
        ->and($transfer->receive_date)->toBeNull()
        ->and($transfer->account_id)->toBeNull();
});

test('a landed transfer keeps its full currency conversion trail exactly', function () {
    $id = insertTransfer(insertAgreement(), insertAccount(), [
        'payment_amount' => '250000.00',
        'amount_transfered_currency' => 'USD',
        'transfer_date' => '2026-04-03',
        'fees' => '450.00',
        'fees_currency' => 'USD',
        'amount_received' => '3206975.00',
        'exchange_rate' => '12.850000',
        'net_amount_received' => '3201192.50',
        'amount_received_currency' => 'SYP',
        'receive_date' => '2026-04-08',
        'ar_status' => 'received',
    ]);

    $transfer = DB::table('transfers_tracking')->find($id);

    expect($transfer->payment_amount)->toBe('250000.00')
        ->and($transfer->fees)->toBe('450.00')
        ->and($transfer->exchange_rate)->toBe('12.850000')
        ->and($transfer->amount_received)->toBe('3206975.00')
        ->and($transfer->net_amount_received)->toBe('3201192.50')
        ->and($transfer->amount_received_currency)->toBe('SYP')
        ->and($transfer->ar_status)->toBe('received');
});

test('a tranche may be scheduled before an account is assigned', function () {
    $id = insertTransfer(insertAgreement());

    expect(DB::table('transfers_tracking')->find($id)->account_id)->toBeNull();
});

test('deleting a funding agreement deletes the tranches under it', function () {
    $agreementId = insertAgreement();
    insertTransfer($agreementId);

    DB::table('donor_proposal')->where('id', $agreementId)->delete();

    $this->assertDatabaseCount('transfers_tracking', 0);
});

test('an account is not deletable while money has passed through it', function () {
    $accountId = insertAccount();
    insertTransfer(insertAgreement(), $accountId);

    expect(fn () => DB::table('accounts')->where('id', $accountId)->delete())
        ->toThrow(QueryException::class);

    $this->assertDatabaseHas('accounts', ['id' => $accountId]);
});
