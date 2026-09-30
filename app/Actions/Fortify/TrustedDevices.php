<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Http\UserAgent;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * "Don't ask again on this device": a browser that passed the second sign-in
 * step (emailed code or authenticator app) with the box ticked skips that
 * step for DaysTrusted days. The password is always still asked for.
 *
 * The browser keeps a random token in an encrypted, HTTP-only cookie named
 * per user; only its SHA-256 hash is stored (TrustedDevice), so a stolen
 * password alone never skips the code on another computer. "Sign out other
 * devices" and "Forget trusted devices" on the security page delete the rows,
 * which ends the trust at once. Trust also ends for every browser when the
 * authenticator app is switched off or new recovery codes are made.
 */
final class TrustedDevices
{
    /**
     * Days a browser skips the second step after it was trusted.
     */
    public const DaysTrusted = 30;

    /**
     * The form field of the "don't ask again" box on the challenge pages.
     */
    public const RememberField = 'remember_device';

    /**
     * Whether this browser is trusted for the user; records the use.
     */
    public static function trusts(Request $request, User $user): bool
    {
        $device = self::current($request, $user);

        $device?->forceFill(['last_used_at' => now(), 'last_ip' => $request->ip()])->save();

        return $device !== null;
    }

    /**
     * The unexpired trust this browser holds for the user, if any.
     */
    public static function current(Request $request, User $user): ?TrustedDevice
    {
        $token = $request->cookie(self::cookieName($user));

        if (! is_string($token) || $token === '') {
            return null;
        }

        return TrustedDevice::query()
            ->where('user_id', $user->getKey())
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * Trust this browser for the user and hand it the cookie.
     */
    public static function trust(Request $request, User $user): void
    {
        $token = Str::random(64);

        TrustedDevice::query()->create([
            'user_id' => $user->getKey(),
            'token_hash' => hash('sha256', $token),
            'label' => UserAgent::parse($request->userAgent())->description(),
            'last_ip' => $request->ip(),
            'last_used_at' => now(),
            'expires_at' => now()->addDays(self::DaysTrusted),
        ]);

        Cookie::queue(Cookie::make(
            self::cookieName($user),
            $token,
            self::DaysTrusted * 24 * 60,
            httpOnly: true,
            sameSite: 'lax',
        ));
    }

    /**
     * End the trust of every browser but this one.
     */
    public static function forgetOthers(Request $request, User $user): void
    {
        TrustedDevice::query()
            ->where('user_id', $user->getKey())
            ->whereKeyNot(self::current($request, $user)?->getKey() ?? 0)
            ->delete();
    }

    /**
     * End the trust of every browser, this one included.
     */
    public static function forgetAll(User $user): void
    {
        TrustedDevice::query()->where('user_id', $user->getKey())->delete();

        Cookie::queue(Cookie::forget(self::cookieName($user)));
    }

    public static function cookieName(User $user): string
    {
        return 'trusted_device_'.$user->getKey();
    }
}
