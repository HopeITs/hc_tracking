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
| The word "cloudflare" in that list stands for every range Cloudflare
| publishes (https://www.cloudflare.com/ips/, copied below on 2026-09-30),
| so the live .env reads TRUSTED_PROXIES=cloudflare instead of 22 hand-typed
| ranges. Compare the copy with that page before relying on it: a range
| missing here is refused by the lockdown below.
|
| TRUSTED_PROXIES_REQUIRED locks the origin down: every request must then
| arrive directly from one of these addresses, or it is refused with a 403
| (App\Http\Middleware\EnsureRequestCameThroughTrustedProxy), so nobody can
| bypass the CDN or load balancer by calling the server's IP. It is defence
| in depth only; the server firewall is the primary control. Off unless set:
| an explicit false/0/off/no keeps it off, and any other value turns it on,
| so a mistyped "ture" does not quietly leave the origin open.
*/

$cloudflareRanges = [
    '173.245.48.0/20',
    '103.21.244.0/22',
    '103.22.200.0/22',
    '103.31.4.0/22',
    '141.101.64.0/18',
    '108.162.192.0/18',
    '190.93.240.0/20',
    '188.114.96.0/20',
    '197.234.240.0/22',
    '198.41.128.0/17',
    '162.158.0.0/15',
    '104.16.0.0/13',
    '104.24.0.0/14',
    '172.64.0.0/13',
    '131.0.72.0/22',
    '2400:cb00::/32',
    '2606:4700::/32',
    '2803:f800::/32',
    '2405:b500::/32',
    '2405:8100::/32',
    '2a06:98c0::/29',
    '2c0f:f248::/32',
];

$trustedProxies = array_values(array_unique(array_merge(...array_map(
    fn (string $entry): array => strtolower($entry) === 'cloudflare' ? $cloudflareRanges : [$entry],
    array_values(array_filter(array_map(trim(...), explode(',', (string) env('TRUSTED_PROXIES', ''))))),
))));

$requiredSetting = env('TRUSTED_PROXIES_REQUIRED');
$requiredSetting = is_string($requiredSetting) ? strtolower(trim($requiredSetting)) : $requiredSetting;
$proxyRequired = ! in_array($requiredSetting, [null, '', false, '0', 'off', 'no'], true);

return [

    'proxies' => $trustedProxies === [] ? null : $trustedProxies,

    'required' => $proxyRequired,

];
