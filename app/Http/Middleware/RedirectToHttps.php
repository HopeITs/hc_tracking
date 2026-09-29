<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Production is HTTPS only. A page asked for over plain http (GET or HEAD) is
 * sent to the same address on https with a 301, before a session starts, so
 * the login form is never served where it can be read or rewritten on the
 * way. Any other method is refused with a 403 rather than redirected: the
 * form data has already crossed the network in the clear, and a redirect
 * would quietly re-send it. Nothing the app generates posts over http, since
 * every URL is forced to https in production.
 *
 * Appended to the global stack so it runs after TrustProxies: behind a
 * trusted proxy, $request->isSecure() follows its X-Forwarded-Proto. A request
 * whose X-Forwarded-Proto already says https is let through even from an
 * untrusted address, so a proxy that is not yet in TRUSTED_PROXIES can never
 * put the site into a redirect loop. That header gains a caller nothing: it
 * only changes the answer to their own request, and the browser never sends
 * the Secure cookies over http.
 *
 * Local and test copies are left alone, so http://127.0.0.1 keeps working.
 */
class RedirectToHttps
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->isProduction() || $request->isSecure() || self::forwardedAsHttps($request)) {
            return $next($request);
        }

        if (! $request->isMethodCacheable()) {
            abort(Response::HTTP_FORBIDDEN);
        }

        return redirect()->away('https://'.$request->getHost().$request->getRequestUri(), Response::HTTP_MOVED_PERMANENTLY);
    }

    /**
     * Whether a proxy in front already says the visitor connected over https.
     */
    private static function forwardedAsHttps(Request $request): bool
    {
        return strtolower(trim(explode(',', (string) $request->headers->get('X-Forwarded-Proto'))[0])) === 'https';
    }
}
