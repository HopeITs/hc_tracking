<?php

/*
| Origin lockdown (TRUSTED_PROXIES_REQUIRED): once on, only a direct
| connection from a configured proxy reaches the app; the forwarded client
| address is never what is checked.
*/

test('the lockdown is only on when TRUSTED_PROXIES_REQUIRED turns it on', function (?string $value, bool $expected) {
    $original = getenv('TRUSTED_PROXIES_REQUIRED');

    unset($_SERVER['TRUSTED_PROXIES_REQUIRED'], $_ENV['TRUSTED_PROXIES_REQUIRED']);
    putenv($value === null ? 'TRUSTED_PROXIES_REQUIRED' : "TRUSTED_PROXIES_REQUIRED={$value}");

    try {
        $config = require config_path('trustedproxy.php');
    } finally {
        putenv($original === false ? 'TRUSTED_PROXIES_REQUIRED' : "TRUSTED_PROXIES_REQUIRED={$original}");
    }

    expect($config['required'])->toBe($expected);
})->with([
    'unset' => [null, false],
    'empty' => ['', false],
    'false' => ['false', false],
    'off' => [' OFF ', false],
    'true' => ['true', true],
    'one' => ['1', true],
    'mistyped' => ['ture', true],
]);

test('while the lockdown is off any address reaches the app', function () {
    config(['trustedproxy.proxies' => ['10.0.0.0/8']]);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('/login')->assertOk();
});

test('a direct connection from a configured proxy is allowed', function (string $proxies, string $remoteAddress) {
    config(['trustedproxy.required' => true, 'trustedproxy.proxies' => explode(',', $proxies)]);

    $this->withServerVariables(['REMOTE_ADDR' => $remoteAddress])->get('/login')->assertOk();
})->with([
    'inside an IPv4 range' => ['192.0.2.1,10.0.0.0/8', '10.20.30.40'],
    'an exact IPv4 address' => ['192.0.2.1', '192.0.2.1'],
    'inside an IPv6 range' => ['2400:cb00::/32', '2400:cb00:2049:1::a29f:1804'],
]);

test('a direct connection from anywhere else is refused, whatever it forwards', function () {
    config(['app.debug' => false, 'trustedproxy.required' => true, 'trustedproxy.proxies' => ['10.0.0.0/8']]);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '10.0.0.1'])
        ->get('/login')
        ->assertForbidden()
        ->assertSee(__('errors.403_heading'));
});

test('the lockdown fails closed when no proxy is configured', function () {
    config(['trustedproxy.required' => true, 'trustedproxy.proxies' => null]);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->get('/up')->assertForbidden();
});
