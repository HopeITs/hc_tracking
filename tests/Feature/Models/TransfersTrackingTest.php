<?php

use App\Enums\ArStatus;
use App\Enums\Currency;
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

test('a tranche rounds monetary amounts and exchange rates to their column precision', function () {
    $tranche = TransfersTracking::factory()->make([
        'payment_amount' => '123456789012.345',
        'fees' => '123.455',
        'amount_received' => '987654321098.765',
        'net_amount_received' => '987654320975.305',
        'exchange_rate' => '123456.1234567',
    ]);

    expect($tranche->payment_amount)->toBe('123456789012.35')
        ->and($tranche->fees)->toBe('123.46')
        ->and($tranche->amount_received)->toBe('987654321098.77')
        ->and($tranche->net_amount_received)->toBe('987654320975.31')
        ->and($tranche->exchange_rate)->toBe('123456.123457');
});

test('every tranche state reads the same in SQL as on the model', function () {
    $this->travelTo('2026-06-15 09:00:00');

    $tranches = collect([
        TransfersTracking::factory()->create(['ar_status' => ArStatus::Received]),
        TransfersTracking::factory()->create(['ar_status' => ArStatus::Failed]),
        TransfersTracking::factory()->create(['transfer_date' => '2026-06-10', 'scheduled_date' => '2026-05-01']),
        TransfersTracking::factory()->create(['transfer_date' => null, 'scheduled_date' => '2026-06-14']),
        TransfersTracking::factory()->create(['transfer_date' => null, 'scheduled_date' => '2026-06-15']),
        TransfersTracking::factory()->create(['transfer_date' => null, 'scheduled_date' => null]),
    ])->map->fresh();

    foreach (TransfersTracking::STATES as $state) {
        expect(TransfersTracking::query()->inState($state)->orderBy('id')->pluck('id')->all())
            ->toBe($tranches->filter(fn (TransfersTracking $t): bool => $t->state() === $state)->pluck('id')->values()->all(), $state);
    }

    expect($tranches->map->state()->all())->toBe(['received', 'failed', 'transit', 'late', 'upcoming', 'upcoming']);
});

test('settled, landed short, missing net and converted tranches are told apart', function () {
    $settled = TransfersTracking::factory()->create(['ar_status' => ArStatus::Received, 'payment_amount' => '100.00', 'net_amount_received' => '90.00', 'amount_transfered_currency' => Currency::Usd, 'amount_received_currency' => Currency::Usd]);
    $missing = TransfersTracking::factory()->create(['ar_status' => ArStatus::Received, 'net_amount_received' => null, 'amount_received' => null]);
    $converted = TransfersTracking::factory()->create(['ar_status' => ArStatus::Received, 'net_amount_received' => '80.00', 'amount_transfered_currency' => Currency::Usd, 'amount_received_currency' => Currency::Eur]);
    $pending = TransfersTracking::factory()->create(['ar_status' => ArStatus::Pending, 'net_amount_received' => '5.00']);

    expect(TransfersTracking::query()->settled()->pluck('id')->all())->toBe([$settled->id])
        ->and(TransfersTracking::query()->whereRaw(TransfersTracking::landedShortSql())->pluck('id')->all())->toBe([$settled->id])
        ->and(TransfersTracking::query()->missingNet()->pluck('id')->all())->toBe([$missing->id])
        ->and(TransfersTracking::query()->converted()->pluck('id')->all())->toBe([$converted->id])
        ->and($settled->fresh()->isSettled())->toBeTrue()
        ->and($settled->fresh()->difference())->toBe(-10.0)
        ->and($converted->fresh()->landedInAnotherCurrency())->toBeTrue()
        ->and($converted->fresh()->difference())->toBeNull()
        ->and($pending->fresh()->difference())->toBeNull();
});

test('a date range reads the date its basis names and leaves undated tranches out', function () {
    $tranche = TransfersTracking::factory()->create(['scheduled_date' => '2025-12-20', 'transfer_date' => '2026-01-05', 'receive_date' => '2026-02-10', 'ar_status' => ArStatus::Received]);
    TransfersTracking::factory()->create(['scheduled_date' => null, 'transfer_date' => null]);

    expect(TransfersTracking::query()->datedWithin('scheduled', '2025-12-01', '2025-12-31')->pluck('id')->all())->toBe([$tranche->id])
        ->and(TransfersTracking::query()->datedWithin('sent', '2026-01-01', null)->pluck('id')->all())->toBe([$tranche->id])
        ->and(TransfersTracking::query()->datedWithin('received', null, '2026-01-31')->count())->toBe(0)
        ->and(TransfersTracking::query()->datedWithin('nonsense', '2025-12-01', '2025-12-31')->count())->toBe(1);
});
