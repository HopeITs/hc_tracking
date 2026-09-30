<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;

/*
| Every response carries the browser hardening headers from
| App\Http\Middleware\AddSecurityHeaders (global, outermost) and
| App\Http\Middleware\SecurityHeaders (web group, full CSP). Framing stays
| same-origin (not DENY) because the proposal, report and task pages embed PDF
| previews.
*/
test('pages carry the hardening headers and allow only same-origin framing', function () {
    $response = $this->get(route('login'));

    $response->assertOk()
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
        ->assertHeaderMissing('Strict-Transport-Security');

    expect($response->headers->get('Permissions-Policy'))->toContain('camera=()');

    expect($response->headers->get('Content-Security-Policy'))
        ->toContain("default-src 'self'")
        ->toContain("object-src 'none'")
        ->toContain("frame-ancestors 'self'")
        ->toContain("base-uri 'self'")
        ->toContain("form-action 'self'")
        ->not->toContain('upgrade-insecure-requests');
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

it('upgrades insecure requests over https', function () {
    expect($this->get('https://localhost/login')->headers->get('Content-Security-Policy'))
        ->toContain('upgrade-insecure-requests');
});

it('allows the configured push service to be reached', function () {
    config(['broadcasting.connections.pusher.key' => 'k', 'broadcasting.connections.pusher.options.cluster' => 'eu']);

    expect($this->get(route('login'))->headers->get('Content-Security-Policy'))
        ->toContain('wss://ws-eu.pusher.com')
        ->toContain('https://sockjs-eu.pusher.com');
});

it('allows the Vite dev server only while it is running', function () {
    $hot = storage_path('framework/testing/vite-hot');
    @mkdir(dirname($hot), 0777, true);
    file_put_contents($hot, 'http://[::1]:5173');
    Vite::useHotFile($hot);

    expect($this->get(route('login'))->headers->get('Content-Security-Policy'))->toContain('http://[::1]:5173')
        ->toContain('ws://[::1]:5173');

    unlink($hot);

    expect($this->get(route('login'))->headers->get('Content-Security-Policy'))->not->toContain('5173');
});

it('leaves inline PDF responses without a CSP but keeps the other headers', function () {
    Route::get('/_test/pdf', fn () => response('%PDF-1.4', 200, ['Content-Type' => 'application/pdf']))->middleware('web');

    $this->get('/_test/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeaderMissing('Content-Security-Policy');
});
