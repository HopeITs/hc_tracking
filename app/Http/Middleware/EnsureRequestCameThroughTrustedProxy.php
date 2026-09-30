<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Origin lockdown: while config('trustedproxy.required') is on, only the CDN
 * or load balancer listed in config('trustedproxy.proxies') may reach the
 * app, so nobody can bypass it by calling the server's IP directly. This is
 * defence in depth; the server firewall is the primary control.
 *
 * The address checked is the direct connection (REMOTE_ADDR), never
 * $request->ip(), which is the forwarded client address once TrustProxies
 * believes the proxy's headers, so a forged X-Forwarded-For cannot pass.
 * Entries are IPs or CIDR ranges (IPv4 or IPv6); wildcards such as "*" match
 * nothing here. It fails closed: when required but no proxy is configured,
 * every request is refused with a 403.
 *
 * It is prepended to the global stack, so it runs before sessions and
 * authentication and a refused request costs nothing else.
 */
class EnsureRequestCameThroughTrustedProxy
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (config('trustedproxy.required') && ! self::cameThroughTrustedProxy($request)) {
            abort(Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }

    /**
     * Whether the direct connection comes from a configured trusted proxy.
     */
    private static function cameThroughTrustedProxy(Request $request): bool
    {
        $connectingAddress = $request->server('REMOTE_ADDR');
        $proxies = array_values(array_filter((array) config('trustedproxy.proxies'), is_string(...)));

        return is_string($connectingAddress)
            && $connectingAddress !== ''
            && $proxies !== []
            && IpUtils::checkIp($connectingAddress, $proxies);
    }
}
