<?php

use Illuminate\Support\Facades\Route;

/*
| Every response carries the browser hardening headers from
| App\Http\Middleware\AddSecurityHeaders. Framing stays same-origin (not
| DENY) because the proposal, report and task pages embed PDF previews.
*/
test('pages carry the hardening headers and allow only same-origin framing', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'")
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()')
        ->assertHeaderMissing('Strict-Transport-Security');
});

test('a request refused by the origin lockdown still carries the headers', function () {
    config(['trustedproxy.required' => true, 'trustedproxy.proxies' => ['10.0.0.1']]);

    $this->get(route('login'))
        ->assertForbidden()
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
});

test('a header the response already set is kept and x-powered-by is stripped', function () {
    Route::get('/_test/own-headers', fn () => response('ok')->withHeaders([
        'Content-Security-Policy' => 'sandbox',
        'X-Powered-By' => 'PHP/8.5.10',
    ]));

    $this->get('/_test/own-headers')
        ->assertHeader('Content-Security-Policy', 'sandbox')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeaderMissing('X-Powered-By');
});

test('hsts is sent on https in production with the configured max-age', function (int $maxAge) {
    app()->detectEnvironment(fn (): string => 'production');
    config(['app.hsts_max_age' => $maxAge]);

    $this->get(secure_url('login'))
        ->assertHeader('Strict-Transport-Security', "max-age={$maxAge}");
})->with([
    'default one day' => 86400,
    'rollback' => 0,
]);

test('hsts is never sent over plain http or outside production', function (string $environment, bool $overHttps) {
    app()->detectEnvironment(fn (): string => $environment);

    $this->get($overHttps ? secure_url('login') : url('login'))
        ->assertHeaderMissing('Strict-Transport-Security');
})->with([
    'production over http' => ['production', false],
    'local over https' => ['local', true],
]);
