<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser-side hardening headers on every response.
 *
 * Framing is limited to the app's own origin, not denied outright: the
 * proposal, report and task pages show PDF previews in same-origin iframes.
 * A header a controller already set is left alone, so the file previews keep
 * their own `Content-Security-Policy: sandbox`; X-Frame-Options still guards
 * their framing.
 *
 * HSTS is sent only over HTTPS in production, so a local http:// copy can
 * never be locked out. Its max-age comes from config('app.hsts_max_age');
 * 0 tells browsers to forget an earlier HSTS answer, which is the rollback.
 *
 * Prepended to the global stack ahead of the origin lockdown, so even a
 * refused request's 403 carries the headers.
 */
class AddSecurityHeaders
{
    /**
     * @var array<string, string>
     */
    public const Headers = [
        'X-Frame-Options' => 'SAMEORIGIN',
        'Content-Security-Policy' => "frame-ancestors 'self'",
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (self::Headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        if ($request->isSecure() && app()->isProduction()) {
            $response->headers->set('Strict-Transport-Security', 'max-age='.max(0, (int) config('app.hsts_max_age')));
        }

        // PHP adds X-Powered-By itself (expose_php), outside the response object.
        $response->headers->remove('X-Powered-By');

        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }

        return $response;
    }
}
