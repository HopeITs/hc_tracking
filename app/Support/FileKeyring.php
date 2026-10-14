<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\FileStorageNotConfigured;
use App\Exceptions\UnreadableStoredFile;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;

/**
 * The keys that encrypt uploaded files at rest (contracts §16), read from
 * config/files.php and validated as a whole the first time they are needed.
 *
 * One current key encrypts; previous keys only decrypt, until
 * `security:reencrypt --files` has moved every file off them. The file key is
 * independent from APP_KEY and never goes through the Crypt facade, so the two
 * rotate on their own schedules.
 *
 * It fails closed: any missing or malformed value refuses the whole keyring,
 * because a half-read configuration could store a file nobody can open. The
 * log says which kind of value was wrong, never the value.
 */
final class FileKeyring
{
    private const Cipher = 'aes-256-gcm';

    private const KeyIdPattern = '/^[A-Za-z0-9._-]{1,20}$/';

    /**
     * Memoized for the request; forget() drops it.
     *
     * @var array{current: string, encrypters: array<string, Encrypter>}|null
     */
    private static ?array $keyring = null;

    /**
     * The key every new file is encrypted with, and its id.
     *
     * @return array{id: string, encrypter: Encrypter}
     *
     * @throws FileStorageNotConfigured
     */
    public static function current(): array
    {
        $keyring = self::keyring();

        return ['id' => $keyring['current'], 'encrypter' => $keyring['encrypters'][$keyring['current']]];
    }

    /**
     * The encrypter for a stored file's key id, current or previous.
     *
     * @throws UnreadableStoredFile when the id is unknown or the keyring is unusable
     */
    public static function for(string $keyId): Encrypter
    {
        try {
            $keyring = self::keyring();
        } catch (FileStorageNotConfigured) {
            throw UnreadableStoredFile::forKey($keyId);
        }

        return $keyring['encrypters'][$keyId] ?? throw UnreadableStoredFile::forKey($keyId);
    }

    /**
     * Drop the memoized keyring so the next call reads the config again.
     */
    public static function forget(): void
    {
        self::$keyring = null;
    }

    /**
     * @return array{current: string, encrypters: array<string, Encrypter>}
     */
    private static function keyring(): array
    {
        return self::$keyring ??= self::load();
    }

    /**
     * @return array{current: string, encrypters: array<string, Encrypter>}
     */
    private static function load(): array
    {
        if (config('files.encryption.cipher') !== self::Cipher) {
            self::refuse('unsupported_cipher');
        }

        $currentId = config('files.encryption.key_id');

        if (! is_string($currentId) || preg_match(self::KeyIdPattern, $currentId) !== 1) {
            self::refuse('invalid_key_id');
        }

        $encrypters = [$currentId => self::encrypter(config('files.encryption.key'), 'invalid_key')];

        foreach ((array) config('files.encryption.previous_keys', []) as $entry) {
            // The key itself may carry "base64:", so only the first colon separates.
            $parts = explode(':', (string) $entry, 2);

            if (count($parts) !== 2 || preg_match(self::KeyIdPattern, $parts[0]) !== 1) {
                self::refuse('invalid_previous_key');
            }

            if (isset($encrypters[$parts[0]])) {
                self::refuse('duplicate_key_id');
            }

            $encrypters[$parts[0]] = self::encrypter($parts[1], 'invalid_previous_key');
        }

        return ['current' => $currentId, 'encrypters' => $encrypters];
    }

    /**
     * A GCM encrypter for a 32-byte key given raw or as "base64:...".
     */
    private static function encrypter(#[SensitiveParameter] mixed $key, string $reason): Encrypter
    {
        if (! is_string($key) || $key === '') {
            self::refuse($reason);
        }

        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true);
        }

        if (! is_string($key) || ! Encrypter::supported($key, self::Cipher)) {
            self::refuse($reason);
        }

        return new Encrypter($key, self::Cipher);
    }

    private static function refuse(string $reason): never
    {
        Log::error('File encryption is not configured.', ['reason' => $reason]);

        throw FileStorageNotConfigured::make();
    }
}
