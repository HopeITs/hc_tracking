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
*/

$trustedProxies = array_values(array_filter(array_map(trim(...), explode(',', (string) env('TRUSTED_PROXIES', '')))));

return [

    'proxies' => $trustedProxies === [] ? null : $trustedProxies,

];
