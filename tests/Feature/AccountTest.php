<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
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

test('the encryption migration encrypts plaintext bank details once and redacts them from the activity log', function () {
    $id = DB::table('accounts')->insertGetId([
        'account_name' => 'Operations USD',
        'bank_name' => 'Fransbank',
        'currency' => 'USD',
        'account_number' => '030/02/353/2531267/1/3',
        'iban' => 'LB03001400003002353253126713',
        'country' => 'Lebanon',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $activityId = DB::table('activities')->insertGetId([
        'subject_type' => 'account',
        'subject_id' => $id,
        'module' => 'accounts',
        'event' => 'account.updated',
        'changes' => json_encode([
            'account_number' => ['030/02/353/2531267/1/2', '030/02/353/2531267/1/3'],
            'iban' => [null, 'LB03001400003002353253126713'],
            'bank_name' => ['Bank Audi', 'Fransbank'],
        ]),
        'properties' => json_encode(['label' => 'Operations USD', 'field' => null]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration = require glob(database_path('migrations/*_encrypt_account_numbers_and_iban_on_accounts_table.php'))[0];
    $migration->encryptExistingValues();
    $migration->encryptExistingValues();
    $migration->redactActivityLog();

    $account = DB::table('accounts')->find($id);
    $activity = DB::table('activities')->find($activityId);

    expect(Crypt::decryptString($account->account_number))->toBe('030/02/353/2531267/1/3')
        ->and(Crypt::decryptString($account->iban))->toBe('LB03001400003002353253126713')
        ->and(json_decode($activity->changes, true))->toEqual([
            'account_number' => ['[redacted]', '[redacted]'],
            'iban' => [null, '[redacted]'],
            'bank_name' => ['Bank Audi', 'Fransbank'],
        ])
        ->and(json_decode($activity->properties, true))->toEqual(['label' => 'Operations USD', 'field' => null]);
});

test('the account number column holds a value too long for the old 100 character limit', function () {
    $id = insertAccount();
    $ciphertext = Crypt::encryptString('030/02/353/2531267/1/3');

    DB::table('accounts')->where('id', $id)->update(['account_number' => $ciphertext, 'iban' => $ciphertext]);

    expect(strlen($ciphertext))->toBeGreaterThan(100)
        ->and(DB::table('accounts')->find($id)->account_number)->toBe($ciphertext)
        ->and(DB::table('accounts')->find($id)->iban)->toBe($ciphertext);
});
