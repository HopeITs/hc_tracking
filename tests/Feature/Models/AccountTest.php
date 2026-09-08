<?php

use App\Models\Account;
use App\Models\TransfersTracking;
use Carbon\CarbonImmutable;

test('an account has the transfers that landed in it', function () {
    $account = Account::factory()->create();

    TransfersTracking::factory(2)->create(['account_id' => $account->id]);
    TransfersTracking::factory()->create();

    expect($account->transfers)->toHaveCount(2)
        ->and($account->transfers->first())->toBeInstanceOf(TransfersTracking::class);
});

test('an open account casts its dates and active flag', function () {
    $account = Account::factory()->create(['opened_at' => '2024-03-15'])->fresh();

    expect($account->opened_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($account->opened_at->toDateString())->toBe('2024-03-15')
        ->and($account->closed_at)->toBeNull()
        ->and($account->is_active)->toBeTrue();
});

test('a closed account is inactive and carries a closing date', function () {
    $account = Account::factory()->closed()->create()->fresh();

    expect($account->is_active)->toBeFalse()
        ->and($account->closed_at)->toBeInstanceOf(CarbonImmutable::class);
});

test('an active account is usable and a closed one is not', function () {
    expect(Account::factory()->create()->isUsable())->toBeTrue()
        ->and(Account::factory()->closed()->create()->isUsable())->toBeFalse();
});

test('closing an account writes both columns together', function () {
    $account = Account::factory()->create();

    expect($account->close())->toBeTrue();

    $account = $account->fresh();

    expect($account->is_active)->toBeFalse()
        ->and($account->closed_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($account->closed_at->toDateString())->toBe(now()->toDateString())
        ->and($account->isUsable())->toBeFalse();
});

test('closing an account accepts an explicit date', function () {
    $account = Account::factory()->create();

    $account->close(CarbonImmutable::parse('2026-06-30'));

    expect($account->fresh()->closed_at->toDateString())->toBe('2026-06-30');
});

test('the active scope returns only accounts that can receive transfers', function () {
    $open = Account::factory()->create();
    Account::factory()->closed()->create();

    expect(Account::active()->pluck('id')->all())->toBe([$open->id]);
});

test('a closed account keeps the transfers that already passed through it', function () {
    $account = Account::factory()->create();
    TransfersTracking::factory()->create(['account_id' => $account->id]);

    $account->close();

    expect($account->fresh()->transfers)->toHaveCount(1);
});
