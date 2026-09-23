<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('a new account is active and has no closing date', function () {
    $account = DB::table('accounts')->find(insertAccount());

    expect((bool) $account->is_active)->toBeTrue()
        ->and($account->closed_at)->toBeNull()
        ->and($account->opened_at)->toBe('2024-03-15')
        ->and($account->account_name)->toBe('Operations USD');
});

test('an account records the date it was closed', function () {
    $id = insertAccount();

    DB::table('accounts')->where('id', $id)->update([
        'closed_at' => '2026-06-30',
        'is_active' => false,
    ]);

    $account = DB::table('accounts')->find($id);

    expect($account->closed_at)->toBe('2026-06-30')
        ->and((bool) $account->is_active)->toBeFalse();
});

test('an account can be stored with no opening date', function () {
    $id = DB::table('accounts')->insertGetId([
        'account_name' => 'Cash Account',
        'bank_name' => 'Cash',
        'currency' => 'USD',
        'account_number' => 'CASH-USD-01',
        'country' => 'Syria',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('accounts')->find($id)->opened_at)->toBeNull();
});

test('an account cannot be stored without a bank name', function () {
    DB::table('accounts')->insert([
        'account_name' => 'Operations USD',
        'currency' => 'USD',
        'account_number' => '12345',
        'country' => 'Syria',
        'opened_at' => '2024-03-15',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);

test('the is_active column defaults to true without the model supplying it', function () {
    $id = DB::table('accounts')->insertGetId([
        'account_name' => 'Raw Insert USD',
        'bank_name' => 'Fransbank',
        'currency' => 'USD',
        'account_number' => '999',
        'country' => 'Syria',
        'opened_at' => '2024-03-15',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect((bool) DB::table('accounts')->find($id)->is_active)->toBeTrue();
});
