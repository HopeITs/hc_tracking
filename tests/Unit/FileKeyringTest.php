<?php

use App\Exceptions\FileStorageNotConfigured;
use App\Exceptions\UnreadableStoredFile;
use App\Support\FileKeyring;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Configure the file keyring directly, bypassing the env defaults, and drop
 * the memoized copy so the next call parses these values.
 *
 * @param  list<string>  $previousKeys  raw "id:key" entries
 */
function configureFileKeyring(?string $key, string $keyId = 'k1', array $previousKeys = []): void
{
    config([
        'files.encryption.key' => $key,
        'files.encryption.key_id' => $keyId,
        'files.encryption.previous_keys' => $previousKeys,
    ]);

    FileKeyring::forget();
}

function randomFileKey(): string
{
    return 'base64:'.base64_encode(random_bytes(32));
}

afterEach(fn () => FileKeyring::forget());

test('the current key accepts the base64 prefix and round-trips raw bytes', function () {
    configureFileKeyring(randomFileKey(), 'k1');

    $current = FileKeyring::current();
    $bytes = random_bytes(64);

    expect($current['id'])->toBe('k1')
        ->and($current['encrypter']->decrypt($current['encrypter']->encrypt($bytes, false), false))->toBe($bytes);
});

test('the current key also accepts 32 raw bytes without the prefix', function () {
    configureFileKeyring(str_repeat('a', 32), 'k1');

    expect(FileKeyring::current()['id'])->toBe('k1');
});

test('the keyring is memoized until it is forgotten', function () {
    configureFileKeyring(randomFileKey(), 'k1');

    $first = FileKeyring::current()['encrypter'];

    expect(FileKeyring::current()['encrypter'])->toBe($first);

    configureFileKeyring(randomFileKey(), 'k2');

    expect(FileKeyring::current()['encrypter'])->not->toBe($first)
        ->and(FileKeyring::current()['id'])->toBe('k2');
});

test('an unusable current key refuses to store anything', function (?string $key) {
    configureFileKeyring($key);

    FileKeyring::current();
})->with([
    'missing' => [null],
    'empty' => [''],
    'too short' => ['base64:'.base64_encode(random_bytes(16))],
    'not base64' => ['base64:%%%not-base64%%%'],
    'raw but too long' => [str_repeat('a', 33)],
])->throws(FileStorageNotConfigured::class);

test('a malformed key id refuses to store anything', function (string $keyId) {
    configureFileKeyring(randomFileKey(), $keyId);

    FileKeyring::current();
})->with([
    'empty' => [''],
    'longer than 20' => [str_repeat('k', 21)],
    'space' => ['k 1'],
    'slash' => ['k/1'],
    'colon' => ['k:1'],
])->throws(FileStorageNotConfigured::class);

test('a malformed previous key refuses to store anything', function (string $entry) {
    configureFileKeyring(randomFileKey(), 'k2', [$entry]);

    FileKeyring::current();
})->with([
    'no separator' => ['k1'],
    'empty id' => [':'.str_repeat('a', 32)],
    'empty key' => ['k1:'],
    'short key' => ['k1:base64:'.base64_encode(random_bytes(16))],
    'bad id' => ['k 1:'.str_repeat('a', 32)],
])->throws(FileStorageNotConfigured::class);

test('a previous key may not reuse the current id', function () {
    configureFileKeyring(randomFileKey(), 'k1', ['k1:'.randomFileKey()]);

    FileKeyring::current();
})->throws(FileStorageNotConfigured::class);

test('a refused keyring names the field the upload forms show', function () {
    configureFileKeyring(null);

    try {
        FileKeyring::current();
    } catch (FileStorageNotConfigured $exception) {
        expect($exception->errors())->toBe(['newFile' => [__('errors.file_storage_not_configured')]]);

        return;
    }

    $this->fail('FileStorageNotConfigured was not thrown.');
});

test('a previous key splits at the first colon and decrypts what it encrypted', function () {
    $oldKey = randomFileKey();

    configureFileKeyring($oldKey, 'k1');
    $payload = FileKeyring::current()['encrypter']->encrypt('legacy bytes', false);

    configureFileKeyring(randomFileKey(), 'k2', ['k1:'.$oldKey]);

    expect(FileKeyring::for('k1')->decrypt($payload, false))->toBe('legacy bytes')
        ->and(FileKeyring::for('k2'))->toBe(FileKeyring::current()['encrypter']);
});

test('an unknown key id cannot be read', function () {
    configureFileKeyring(randomFileKey(), 'k2');

    FileKeyring::for('k1');
})->throws(UnreadableStoredFile::class);

test('reading with a broken keyring is unreadable, not a validation error', function () {
    configureFileKeyring(null);

    FileKeyring::for('k1');
})->throws(UnreadableStoredFile::class);
