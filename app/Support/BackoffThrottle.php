<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;

/**
 * Exponential backoff on top of a fixed rate limit.
 *
 * A fixed limit ("10 failures per 15 minutes") resets by itself, so a patient
 * guesser gets the same budget again and again, all day. Each time a limit
 * trips, escalate() locks the key out for twice as long as the last time
 * (base, 2x, 4x ... up to a cap), and the lockout count is only forgotten
 * after a day without trips or when clear() is called (a right password).
 */
final class BackoffThrottle
{
    /**
     * How long, in seconds, the number of lockouts is remembered after the
     * last one.
     */
    private const MemorySeconds = 86400;

    /**
     * Seconds left on the lockout for this key, 0 when it is not locked.
     */
    public static function lockedForSeconds(string $key): int
    {
        $state = Cache::get(self::cacheKey($key));

        if (! is_array($state)) {
            return 0;
        }

        return max(0, (int) $state['until'] - Date::now()->unix());
    }

    /**
     * Lock the key out again, for longer than the last lockout.
     */
    public static function escalate(string $key, int $baseSeconds, int $capSeconds): void
    {
        $state = Cache::get(self::cacheKey($key));
        $lockouts = is_array($state) ? (int) $state['lockouts'] + 1 : 1;

        Cache::put(self::cacheKey($key), [
            'lockouts' => $lockouts,
            'until' => Date::now()->unix() + self::delaySeconds($lockouts, $baseSeconds, $capSeconds),
        ], self::MemorySeconds);
    }

    /**
     * Forget every lockout of this key.
     */
    public static function clear(string $key): void
    {
        Cache::forget(self::cacheKey($key));
    }

    /**
     * The lockout length for the nth lockout: base, then doubled each time.
     */
    public static function delaySeconds(int $lockouts, int $baseSeconds, int $capSeconds): int
    {
        return (int) min($capSeconds, $baseSeconds * (2 ** max(0, min($lockouts, 30) - 1)));
    }

    private static function cacheKey(string $key): string
    {
        return 'backoff:'.$key;
    }
}
