<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds the browser-hardening response headers to every web response.
 *
 * Headers a controller already set (the file previews send their own
 * `Content-Security-Policy: sandbox`) are left untouched. The CSP keeps
 * 'unsafe-inline' and 'unsafe-eval' for scripts because Livewire, Alpine and
 * the inline toast/preloader scripts need them; everything else is locked to
 * this origin, which still blocks framing, plugins, base-tag and form
 * hijacking, and any request to a third-party host.
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

        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    private function contentSecurityPolicy(Request $request): string
    {
        $devServer = $this->viteDevServer();
        $sockets = $this->socketSources();

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => array_filter(["'self'", "'unsafe-inline'", "'unsafe-eval'", $devServer]),
            'style-src' => array_filter(["'self'", "'unsafe-inline'", $devServer]),
            'img-src' => ["'self'", 'data:', 'blob:'],
            'font-src' => array_filter(["'self'", 'data:', $devServer]),
            'connect-src' => array_filter(["'self'", $devServer, ...$sockets]),
            'frame-src' => ["'self'"],
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
