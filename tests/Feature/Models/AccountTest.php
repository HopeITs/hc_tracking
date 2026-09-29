<?php

use App\Models\Account;
use App\Models\Activity;
use App\Models\TransfersTracking;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

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

test('the account number and IBAN are stored encrypted and read back in plaintext', function () {
    $account = Account::factory()->create([
        'account_number' => '030/02/353/2531267/1/3',
        'iban' => 'LB03001400003002353253126713',
    ]);

    $stored = DB::table('accounts')->find($account->id);

    expect($stored->account_number)->not->toContain('2531267')
        ->and(Crypt::decryptString($stored->account_number))->toBe('030/02/353/2531267/1/3')
        ->and(Crypt::decryptString($stored->iban))->toBe('LB03001400003002353253126713')
        ->and($account->fresh()->account_number)->toBe('030/02/353/2531267/1/3')
        ->and($account->fresh()->iban)->toBe('LB03001400003002353253126713');
});

test('an account with no IBAN stores null, not an encrypted empty value', function () {
    $account = Account::factory()->create(['iban' => null]);

    expect(DB::table('accounts')->find($account->id)->iban)->toBeNull()
        ->and($account->fresh()->iban)->toBeNull();
});

test('account numbers and IBANs are kept out of the activity log', function () {
    $account = Activity::withoutRecording(fn () => Account::factory()->create([
        'account_number' => '4040Z06926201',
        'iban' => 'LB00000000000000000000000001',
    ]));

    $account->update([
        'account_number' => '4040Z06926299',
        'iban' => 'LB00000000000000000000000099',
        'bank_name' => 'Bank Audi',
    ]);

    $activity = Activity::query()->where('subject_type', 'account')->sole();
    $storedLog = json_encode(DB::table('activities')->find($activity->id));

    expect($activity->changes)->toHaveKey('bank_name')
        ->not->toHaveKey('account_number')
        ->not->toHaveKey('iban')
        ->and($storedLog)->not->toContain('4040Z069262')
        ->not->toContain('LB000000000000000000000000');
});

test('the account number and IBAN are still serialized', function () {
    $account = Account::factory()->create(['account_number' => '21.13.0000803.11', 'iban' => 'LB03001400003002353253126713']);

    expect($account->fresh()->toArray())->toMatchArray([
        'account_number' => '21.13.0000803.11',
        'iban' => 'LB03001400003002353253126713',
    ]);
});

test('accounts are found by part of their account number, ignoring case', function () {
    $matching = Account::factory()->create(['account_number' => 'NRA201000409112768']);
    $alsoMatching = Account::factory()->create(['account_number' => 'nra201000340603445']);
    Account::factory()->create(['account_number' => '4040Z06926201']);

    expect(Account::idsMatchingAccountNumber(' nra2010 '))->toEqualCanonicalizing([$matching->id, $alsoMatching->id])
        ->and(Account::idsMatchingAccountNumber('no-such-number'))->toBe([])
        ->and(Account::idsMatchingAccountNumber(''))->toBe([]);
});
