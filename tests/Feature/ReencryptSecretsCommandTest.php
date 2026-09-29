<?php

use App\Models\Account;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Fortify;

/**
 * Point the app at a new APP_KEY (and previous keys) and drop the resolved
 * encrypter so the next Crypt / Fortify / cast call uses them.
 *
 * @param  list<string>  $previousKeys
 */
function useAppKey(string $key, array $previousKeys = []): void
{
    config(['app.key' => $key, 'app.previous_keys' => $previousKeys]);
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');
}

function newAppKey(): string
{
    return 'base64:'.base64_encode(random_bytes(32));
}

/**
 * Plaintext of every encrypted column, read raw and decrypted with the
 * current key only.
 *
 * @return array{secret: string, codes: string, number: string, iban: string}
 */
function decryptedSecrets(int $userId, int $accountId): array
{
    $user = DB::table('users')->find($userId);
    $account = DB::table('accounts')->find($accountId);

    return [
        'secret' => Fortify::currentEncrypter()->decrypt($user->two_factor_secret),
        'codes' => Fortify::currentEncrypter()->decrypt($user->two_factor_recovery_codes),
        'number' => Crypt::decryptString($account->account_number),
        'iban' => Crypt::decryptString($account->iban),
    ];
}

beforeEach(function () {
    $this->oldKey = newAppKey();
    $this->newKey = newAppKey();
    useAppKey($this->oldKey);

    $this->plain = [
        'secret' => 'JBSWY3DPEHPK3PXPSECRET',
        'codes' => json_encode(['recovery-code-one', 'recovery-code-two']),
        'number' => '0099887766554433',
        'iban' => 'SY21CBSY0000000000009876543',
    ];

    $this->userId = User::factory()->create()->id;
    DB::table('users')->where('id', $this->userId)->update([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($this->plain['secret']),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt($this->plain['codes']),
    ]);

    $this->accountId = Account::factory()->create([
        'account_number' => $this->plain['number'],
        'iban' => $this->plain['iban'],
    ])->id;

    useAppKey($this->newKey, [$this->oldKey]);
});

test('every encrypted value still decrypts once the old key is dropped', function () {
    $this->artisan('security:reencrypt')
        ->expectsOutputToContain('users.two_factor_secret: 1 re-encrypted')
        ->expectsOutputToContain('users.two_factor_recovery_codes: 1 re-encrypted')
        ->expectsOutputToContain('accounts.account_number: 1 re-encrypted')
        ->expectsOutputToContain('accounts.iban: 1 re-encrypted')
        ->assertSuccessful();

    useAppKey($this->newKey);

    expect(decryptedSecrets($this->userId, $this->accountId))->toBe($this->plain);
});

test('a dry run writes nothing', function () {
    $usersBefore = DB::table('users')->find($this->userId);
    $accountBefore = DB::table('accounts')->find($this->accountId);

    $this->artisan('security:reencrypt', ['--dry-run' => true])
        ->expectsOutputToContain('users.two_factor_secret: 1 decrypted (dry run)')
        ->assertSuccessful();

    expect(DB::table('users')->find($this->userId))->toEqual($usersBefore)
        ->and(DB::table('accounts')->find($this->accountId))->toEqual($accountBefore);

    useAppKey($this->newKey);

    expect(fn () => decryptedSecrets($this->userId, $this->accountId))->toThrow(DecryptException::class);
});

test('the output never contains a secret value', function () {
    $command = $this->artisan('security:reencrypt');

    foreach ([$this->plain['secret'], 'recovery-code-one', $this->plain['number'], $this->plain['iban']] as $secret) {
        $command->doesntExpectOutputToContain($secret);
    }

    $command->assertSuccessful();
});

test('an undecryptable value fails the command without stopping the other rows', function () {
    $foreignKey = new Encrypter(random_bytes(32), 'AES-256-CBC');
    $brokenId = Account::factory()->create()->id;
    DB::table('accounts')->where('id', $brokenId)->update([
        'account_number' => $foreignKey->encryptString('1111222233334444'),
    ]);

    $this->artisan('security:reencrypt')
        ->expectsOutputToContain("accounts.account_number: 1 failed to decrypt with any known key (ids: {$brokenId})")
        ->doesntExpectOutputToContain('1111222233334444')
        ->assertFailed();

    useAppKey($this->newKey);

    expect(decryptedSecrets($this->userId, $this->accountId))->toBe($this->plain);
});

test('re-encrypting records no activity', function () {
    $before = Activity::query()->count();

    $this->artisan('security:reencrypt')->assertSuccessful();

    expect(Activity::query()->count())->toBe($before);
});
