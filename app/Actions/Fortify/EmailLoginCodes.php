<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use App\Notifications\EmailLoginCode;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * One-time sign-in codes for email two-factor authentication.
 *
 * Only a hash of the code is kept (in the cache, per user), it expires after
 * a few minutes, works once, and is thrown away after too many wrong guesses,
 * so guessing a 6-digit code is not practical.
 */
final class EmailLoginCodes
{
    /**
     * Minutes a code stays valid.
     */
    public const MinutesValid = 10;

    /**
     * Wrong guesses allowed before the code is thrown away.
     */
    public const MaxAttempts = 5;

    /**
     * Seconds a code check may hold the per-user lock before it expires on its own.
     */
    public const LockSeconds = 5;

    /**
     * Seconds a code check waits for the per-user lock before giving up.
     */
    public const LockWaitSeconds = 3;

    /**
     * Create a new code for the user, replacing any earlier one, and email it.
     * Returns false (after reporting the error) when the email could not be
     * sent, leaving no code behind.
     */
    public static function send(User $user): bool
    {
        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);

        Cache::put(self::cacheKey($user), [
            'hash' => Hash::make($code),
            'attempts' => 0,
        ], now()->addMinutes(self::MinutesValid));

        try {
            $user->notifyNow(new EmailLoginCode($code, self::MinutesValid));
        } catch (Throwable $exception) {
            Cache::forget(self::cacheKey($user));
            report($exception);

            return false;
        }

        return true;
    }

    /**
     * Whether the code is the user's current one. A right code is used up; a
     * wrong one counts towards MaxAttempts.
     *
     * Checks run one at a time per user (behind a cache lock), so parallel
     * requests cannot each read the same attempt count and together try more
     * than MaxAttempts guesses. A check that cannot get its turn in time is
     * refused without touching the code or the attempt count.
     */
    public static function verify(User $user, #[\SensitiveParameter] string $code): bool
    {
        try {
            return Cache::lock(self::lockKey($user), self::LockSeconds)
                ->block(self::LockWaitSeconds, fn (): bool => self::verifyWhileLocked($user, $code));
        } catch (LockTimeoutException) {
            return false;
        }
    }

    private static function verifyWhileLocked(User $user, #[\SensitiveParameter] string $code): bool
    {
        $stored = Cache::get(self::cacheKey($user));

        if (! is_array($stored) || $stored['attempts'] >= self::MaxAttempts) {
            return false;
        }

        if (preg_match('/^\d{6}$/', $code) === 1 && Hash::check($code, $stored['hash'])) {
            Cache::forget(self::cacheKey($user));

            return true;
        }

        $stored['attempts']++;

        $stored['attempts'] >= self::MaxAttempts
            ? Cache::forget(self::cacheKey($user))
            : Cache::put(self::cacheKey($user), $stored, now()->addMinutes(self::MinutesValid));

        return false;
    }

    private static function lockKey(User $user): string
    {
        return 'email-login-code-lock:'.$user->getKey();
    }

    private static function cacheKey(User $user): string
    {
        return 'email-login-code:'.$user->getKey();
    }
}
