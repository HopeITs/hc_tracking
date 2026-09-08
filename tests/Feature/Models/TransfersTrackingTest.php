<?php

use App\Models\Account;
use App\Models\DonorProposal;
use App\Models\TransfersTracking;
use Carbon\CarbonImmutable;

test('a tranche resolves the transfers_tracking table', function () {
    expect((new TransfersTracking)->getTable())->toBe('transfers_tracking');
});

test('a tranche belongs to a funding agreement', function () {
    $agreement = DonorProposal::factory()->create();
    $tranche = TransfersTracking::factory()->create(['donor_proposal_id' => $agreement->id]);

    expect($tranche->donorProposal)->toBeInstanceOf(DonorProposal::class)
        ->and($tranche->donorProposal->id)->toBe($agreement->id);
});

test('a tranche has no account until one is assigned', function () {
    $account = Account::factory()->create();

    expect(TransfersTracking::factory()->create()->account)->toBeNull()
        ->and(TransfersTracking::factory()->received($account)->create()->account->id)->toBe($account->id);
});

test('a tranche casts its money columns and dates', function () {
    $tranche = TransfersTracking::factory()->create([
        'payment_amount' => '250000.5',
        'scheduled_date' => '2026-06-30',
        'amount_received' => '250000.5',
        'net_amount_received' => '249500.5',
    ])->fresh();

    expect($tranche->payment_amount)->toBe('250000.50')
        ->and($tranche->amount_received)->toBe('250000.50')
        ->and($tranche->net_amount_received)->toBe('249500.50')
        ->and($tranche->fees)->toBe('0.00')
        ->and($tranche->exchange_rate)->toBe('1.000000')
        ->and($tranche->scheduled_date)->toBeInstanceOf(CarbonImmutable::class)
        ->and($tranche->scheduled_date->toDateString())->toBe('2026-06-30')
        ->and($tranche->transfer_date)->toBeNull()
        ->and($tranche->receive_date)->toBeNull();
});
