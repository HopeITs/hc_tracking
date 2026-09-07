<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('a new account is active and has no closing date', function () {
    $account = DB::table('accounts')->find(insertAccount());

    expect((bool) $account->is_active)->toBeTrue()
        ->and($account->closed_at)->toBeNull()
        ->and($account->opened_at)->toBe('2024-03-15')
        ->and($account->name)->toBe('Operations USD');
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

test('an account cannot be stored without an opening date', function () {
    DB::table('accounts')->insert([
        'name' => 'Operations USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);
