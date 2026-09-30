<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limits, per IP, the requests that arrive without a session cookie this app
 * issued.
 *
 * Every such request starts a new session, which is a new row in the
 * sessions table, so a script that never sends cookies (or sends made-up
 * ones) could fill the database one row per request. A browser only arrives
 * without its cookie on its first visit, so real people never come near the
 * limit, even a whole office opening the site behind one IP.
 *
 * It sits in the global stack (after TrustProxies), so it runs before routing
 * and before the session starts: a refused request writes no session and gets
 * a bare response rather than a rendered error page, so refusing it costs
 * next to nothing. The `guest` rate limiter covers signed-out requests that
 * do carry a session cookie.
 */
class ThrottleRequestsWithoutSession
{
    /**
     * Requests without an issued session cookie one IP may make per minute.
     */
    public const MaxPerMinute = 120;

    public function __construct(private Encrypter $encrypter) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->carriesIssuedSessionCookie($request)) {
            return $next($request);
        }

        $key = 'requests-without-session:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MaxPerMinute)) {
            return new Response('Too Many Requests', Response::HTTP_TOO_MANY_REQUESTS, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Retry-After' => (string) RateLimiter::availableIn($key),
            ]);
        }

        RateLimiter::hit($key);

        return $next($request);
    }

    /**
     * Whether the session cookie is one this app encrypted. Cookies are not
     * decrypted yet in the global stack, so it is checked the way
     * EncryptCookies does:
     * a made-up value fails here and counts as no cookie at all.
     */
    private function carriesIssuedSessionCookie(Request $request): bool
    {
        $name = (string) config('session.cookie');
        $cookie = $request->cookies->get($name);

        if (! is_string($cookie) || $cookie === '') {
            return false;
        }

        try {
            $value = $this->encrypter->decrypt($cookie, false);
        } catch (DecryptException) {
            return false;
        }

        return is_string($value) && CookieValuePrefix::validate($name, $value, $this->encrypter->getAllKeys()) !== null;
    }
}
