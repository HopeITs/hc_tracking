<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Turnstile;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds the browser-hardening response headers to every response.
 *
 * Prepended to the global stack ahead of the origin lockdown, so even a
 * refused request's 403 carries the headers. Headers a controller already set (the file previews send their own
 * `Content-Security-Policy: sandbox`) are left untouched. The CSP keeps
 * 'unsafe-inline' and 'unsafe-eval' for scripts because Livewire, Alpine and
 * the inline toast/preloader scripts need them; everything else is locked to
 * this origin, which still blocks framing, plugins, base-tag and form
 * hijacking, and any request to a third-party host. The one exception is
 * the login page while Turnstile is on, which may load Cloudflare's widget.
 *
 * HSTS is sent only over HTTPS in production, so a local http:// copy can
 * never be locked out. Its max-age comes from config('app.hsts_max_age');
 * 0 tells browsers to forget an earlier HSTS answer, which is the rollback.
 */
class SecurityHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];

        // The browser's built-in PDF viewer does not render under a CSP, so
        // inline PDF previews are served without one (the file-preview
        // controllers decide that; the other headers above still apply).
        if (! str_starts_with((string) $response->headers->get('Content-Type'), 'application/pdf')) {
            $headers['Content-Security-Policy'] = $this->contentSecurityPolicy($request);
        }

        foreach ($headers as $name => $value) {
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

    private function contentSecurityPolicy(Request $request): string
    {
        $devServer = $this->viteDevServer();
        $sockets = $this->socketSources();
        $humanCheck = $this->humanCheckSource($request);

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => array_filter(["'self'", "'unsafe-inline'", "'unsafe-eval'", $devServer, $humanCheck]),
            'style-src' => array_filter(["'self'", "'unsafe-inline'", $devServer]),
            'img-src' => ["'self'", 'data:', 'blob:'],
            'font-src' => array_filter(["'self'", 'data:', $devServer]),
            'connect-src' => array_filter(["'self'", $devServer, $this->webSocketOrigin($devServer), ...$sockets]),
            'frame-src' => array_filter(["'self'", $humanCheck]),
            'worker-src' => ["'self'"],
            'manifest-src' => ["'self'"],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => ["'self'"],
        ];

        $policy = collect($directives)
            ->map(fn (array $sources, string $directive): string => $directive.' '.implode(' ', $sources))
            ->implode('; ');

        return $request->isSecure() ? $policy.'; upgrade-insecure-requests' : $policy;
    }

    /**
     * Cloudflare's origin for the Turnstile widget, on the login page only
     * and only while the human check is switched on, else null.
     */
    private function humanCheckSource(Request $request): ?string
    {
        return Turnstile::isEnabled() && $request->routeIs('login') ? Turnstile::Origin : null;
    }

    /**
     * The Vite dev server origin while `npm run dev` is running, else null.
     */
    private function viteDevServer(): ?string
    {
        if (! Vite::isRunningHot()) {
            return null;
        }

        $origin = trim((string) @file_get_contents(Vite::hotFile()));

        return $origin === '' ? null : $origin;
    }

    /**
     * The ws:// twin of the Vite dev server origin, for its hot-reload socket.
     */
    private function webSocketOrigin(?string $devServer): ?string
    {
        return $devServer === null ? null : preg_replace('/^http/', 'ws', $devServer);
    }

    /**
     * WebSocket / long-poll hosts the notification script connects to.
     *
     * @return list<string>
     */
    private function socketSources(): array
    {
        $sources = [];

        if (filled(config('broadcasting.connections.pusher.key'))) {
            $cluster = (string) config('broadcasting.connections.pusher.options.cluster');
            $sources[] = 'wss://ws-'.$cluster.'.pusher.com';
            $sources[] = 'https://sockjs-'.$cluster.'.pusher.com';
        }

        if (filled(config('broadcasting.connections.reverb.key'))) {
            $host = (string) config('broadcasting.connections.reverb.options.host');
            $port = (string) config('broadcasting.connections.reverb.options.port');
            $sources[] = 'ws://'.$host.':'.$port;
            $sources[] = 'wss://'.$host.':'.$port;
        }

        return array_values(array_unique($sources));
    }
}
