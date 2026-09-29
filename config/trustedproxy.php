<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Trusted proxies
|--------------------------------------------------------------------------
|
| The reverse proxies whose X-Forwarded-* headers are believed, as a
| comma-separated list of IPs or CIDR ranges in TRUSTED_PROXIES. Laravel's
| TrustProxies middleware reads this key on every request. Empty trusts
| nobody, so request()->ip() stays the connecting address and a client cannot
| pick its own IP (and dodge the per-IP login limit) with a forged header.
| Behind a proxy, list its address here, or every visitor shares the proxy's
| IP and one login limit.
|
| This lives in config rather than bootstrap/app.php because the HTTP kernel
| is configured before .env is loaded, so env() read there would come back
| empty.
|
| TRUSTED_PROXIES_REQUIRED locks the origin down: every request must then
| arrive directly from one of these addresses, or it is refused with a 403
| (App\Http\Middleware\EnsureRequestCameThroughTrustedProxy), so nobody can
| bypass the CDN or load balancer by calling the server's IP. It is defence
| in depth only; the server firewall is the primary control. Off unless set:
| an explicit false/0/off/no keeps it off, and any other value turns it on,
| so a mistyped "ture" does not quietly leave the origin open.
*/

$trustedProxies = array_values(array_filter(array_map(trim(...), explode(',', (string) env('TRUSTED_PROXIES', '')))));

$requiredSetting = env('TRUSTED_PROXIES_REQUIRED');
$requiredSetting = is_string($requiredSetting) ? strtolower(trim($requiredSetting)) : $requiredSetting;
$proxyRequired = ! in_array($requiredSetting, [null, '', false, '0', 'off', 'no'], true);

return [

    'proxies' => $trustedProxies === [] ? null : $trustedProxies,

    'required' => $proxyRequired,

];
