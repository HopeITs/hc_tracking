<?php

use Illuminate\Support\Facades\Vite;

it('sends the hardening headers on a public page', function () {
    $response = $this->get(route('login'));

    $response->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
        ->assertHeaderMissing('Strict-Transport-Security');

    $policy = $response->headers->get('Content-Security-Policy');

    expect($policy)
        ->toContain("default-src 'self'")
        ->toContain("object-src 'none'")
        ->toContain("frame-ancestors 'self'")
        ->toContain("base-uri 'self'")
        ->toContain("form-action 'self'")
        ->not->toContain('upgrade-insecure-requests');
});

it('adds HSTS and upgrades insecure requests over https', function () {
    $response = $this->get('https://localhost/login');

    $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    expect($response->headers->get('Content-Security-Policy'))->toContain('upgrade-insecure-requests');
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

    expect($this->get(route('login'))->headers->get('Content-Security-Policy'))->toContain('http://[::1]:5173');

    unlink($hot);

    expect($this->get(route('login'))->headers->get('Content-Security-Policy'))->not->toContain('5173');
});

it('leaves inline PDF responses without a CSP but keeps the other headers', function () {
    Route::get('/_test/pdf', fn () => response('%PDF-1.4', 200, ['Content-Type' => 'application/pdf']))->middleware('web');

    $this->get('/_test/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeaderMissing('Content-Security-Policy');
});
