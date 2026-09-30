<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

/**
 * Browsers that have completed a sign-in for an email before.
 *
 * The limit on failed logins per email counts guesses from every IP, so
 * anyone who knows a colleague's address can fail on purpose and lock that
 * person out. A recognised browser is not caught by that shared lock: its
 * failures are counted on a limit of its own (same size, same backoff), so
 * the account's owner can still sign in from the browser they always use
 * while strangers stay locked out.
 *
 * Recognition is a cookie handed out after a completed sign-in (password and
 * second step). It lives in the app's encrypted cookies, so it cannot be
 * forged or edited, and holds a random browser id plus a fingerprint (never
 * the address) of each email that signed in there. It proves nothing about
 * who is typing: the password, the second step, the per-IP limit and
 * Fortify's own limit all still apply to a recognised browser.
 */
final class RecognisedBrowsers
{
    public const CookieName = 'recognised_browser';

    /**
     * Days a browser stays recognised for an email after its last sign-in.
     */
    public const DaysRecognised = 180;

    /**
     * Emails one browser is recognised for (a shared computer); the ones
     * signed in longest ago are dropped first.
     */
    public const MaxEmails = 5;

    /**
     * The rate limiter key failed logins for this email count on when they
     * come from a browser recognised for it, null for any other browser.
     */
    public static function failedLoginsKey(Request $request, string $email): ?string
    {
        $recognition = self::recognition($request);
        $fingerprint = self::emailFingerprint($email);

        if ($recognition === null || ($recognition['emails'][$fingerprint] ?? 0) <= Date::now()->unix()) {
            return null;
        }

        return 'login-failures-browser:'.sha1($recognition['id'].'|'.$fingerprint);
    }

    /**
     * Recognise this browser for the email from now on, and hand it the cookie.
     */
    public static function recognise(Request $request, string $email): void
    {
        if (trim($email) === '') {
            return;
        }

        $now = Date::now()->unix();
        $recognition = self::recognition($request) ?? ['id' => Str::random(40), 'emails' => []];

        $emails = array_filter($recognition['emails'], fn (int $expiresAt): bool => $expiresAt > $now);
        $emails[self::emailFingerprint($email)] = $now + self::DaysRecognised * 86400;
        arsort($emails);

        Cookie::queue(Cookie::make(
            self::CookieName,
            (string) json_encode(['id' => $recognition['id'], 'emails' => array_slice($emails, 0, self::MaxEmails, true)]),
            self::DaysRecognised * 24 * 60,
            httpOnly: true,
            sameSite: 'lax',
        ));
    }

    /**
     * What an email is matched by: lower case, without accents or outer
     * spaces, hashed so no address sits in a cookie or a cache key.
     */
    public static function emailFingerprint(string $email): string
    {
        return sha1(Str::transliterate(Str::lower(trim($email))));
    }

    /**
     * The browser id and the emails (fingerprint => expiry timestamp) this
     * request's cookie carries, null when it has none or it is malformed.
     *
     * @return array{id: string, emails: array<string, int>}|null
     */
    private static function recognition(Request $request): ?array
    {
        $cookie = $request->cookie(self::CookieName);
        $decoded = is_string($cookie) ? json_decode($cookie, true) : null;

        if (! is_array($decoded) || ! is_string($decoded['id'] ?? null) || $decoded['id'] === '' || ! is_array($decoded['emails'] ?? null)) {
            return null;
        }

        return [
            'id' => $decoded['id'],
            'emails' => array_filter($decoded['emails'], fn (mixed $expiresAt, int|string $fingerprint): bool => is_string($fingerprint) && is_int($expiresAt), ARRAY_FILTER_USE_BOTH),
        ];
    }
}
