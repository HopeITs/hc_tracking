<?php

use Laravel\Passkeys\Passkeys;

/**
 * Evaluate a config file as it would load with the given environment
 * variables (null removes one), restoring the real environment afterwards.
 *
 * @param  array<string, string|null>  $variables
 * @return array<string, mixed>
 */
function configFileWithEnv(string $file, array $variables): array
{
    $original = [];

    foreach ($variables as $name => $value) {
        $original[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)];

        unset($_SERVER[$name], $_ENV[$name]);
        putenv($name);

        if ($value !== null) {
            $_SERVER[$name] = $_ENV[$name] = $value;
            putenv("{$name}={$value}");
        }
    }

    try {
        return require config_path($file);
    } finally {
        foreach ($original as $name => [$server, $env, $putenv]) {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($putenv === false ? $name : "{$name}={$putenv}");

            if ($server !== null) {
                $_SERVER[$name] = $server;
            }

            if ($env !== null) {
                $_ENV[$name] = $env;
            }
        }
    }
}

test('the session cookie is secure by default when the app is served over https', function (?string $secureCookie) {
    $session = configFileWithEnv('session.php', [
        'APP_URL' => 'https://tracking.example.org',
        'SESSION_SECURE_COOKIE' => $secureCookie,
    ]);

    expect($session['secure'])->toBeTrue();
})->with([
    'unset' => [null],
    'left empty as in .env.example' => [''],
]);

test('the session cookie is not secure by default when the app is served over http', function () {
    $session = configFileWithEnv('session.php', [
        'APP_URL' => 'http://127.0.0.1:8000/',
        'SESSION_SECURE_COOKIE' => '',
    ]);

    expect($session['secure'])->toBeFalse();
});

test('the session cookie is always secure by default in production, even with an http app url', function () {
    $session = configFileWithEnv('session.php', [
        'APP_ENV' => 'production',
        'APP_URL' => 'http://127.0.0.1:8000/',
        'SESSION_SECURE_COOKIE' => null,
    ]);

    expect($session['secure'])->toBeTrue();
});

test('SESSION_SECURE_COOKIE overrides the scheme of the app url', function (string $appUrl, string $secureCookie, bool $expected) {
    $session = configFileWithEnv('session.php', [
        'APP_URL' => $appUrl,
        'SESSION_SECURE_COOKIE' => $secureCookie,
    ]);

    expect($session['secure'])->toBe($expected);
})->with([
    'forced on over http' => ['http://127.0.0.1:8000/', 'true', true],
    'forced off over https' => ['https://tracking.example.org', 'false', false],
]);

test('the passkey origin is the app url without its path or trailing slash', function (string $appUrl, string $origin) {
    config(['app.url' => $appUrl]);

    $fortify = configFileWithEnv('fortify.php', []);

    expect($fortify['passkeys']['allowed_origins'])->toBe([$origin]);
})->with([
    'local with a trailing slash' => ['http://127.0.0.1:8000/', 'http://127.0.0.1:8000'],
    'served under a path' => ['https://tracking.example.org/app/', 'https://tracking.example.org'],
    'already an origin' => ['https://tracking.example.org', 'https://tracking.example.org'],
]);

test('the configured passkey origins carry no path or trailing slash', function () {
    expect(Passkeys::allowedOrigins())
        ->not->toBeEmpty()
        ->each->toMatch('#^https?://[^/?\#]+$#');
});

test('an empty passkey user handle secret falls back to the app key', function (?string $secret) {
    $fortify = configFileWithEnv('fortify.php', ['PASSKEYS_USER_HANDLE_SECRET' => $secret]);

    expect($fortify['passkeys']['user_handle_secret'])
        ->toBe(config('app.key'))
        ->not->toBeEmpty();
})->with([
    'unset' => [null],
    'left empty as in .env.example' => [''],
]);

test('a configured passkey user handle secret is used instead of the app key', function () {
    $fortify = configFileWithEnv('fortify.php', ['PASSKEYS_USER_HANDLE_SECRET' => 'separate-passkey-secret']);

    expect($fortify['passkeys']['user_handle_secret'])->toBe('separate-passkey-secret');
});

test('no proxy is trusted unless TRUSTED_PROXIES lists one', function (?string $value, ?array $expected) {
    expect(configFileWithEnv('trustedproxy.php', ['TRUSTED_PROXIES' => $value])['proxies'])->toBe($expected);
})->with([
    'unset' => [null, null],
    'empty' => ['', null],
    'list' => [' 10.0.0.1 , 192.168.0.0/16 ', ['10.0.0.1', '192.168.0.0/16']],
]);

test('the word cloudflare in TRUSTED_PROXIES stands for every cloudflare range', function (string $value, int $expectedCount) {
    $proxies = configFileWithEnv('trustedproxy.php', ['TRUSTED_PROXIES' => $value])['proxies'];

    expect($proxies)
        ->toHaveCount($expectedCount)
        ->toContain('173.245.48.0/20', '172.64.0.0/13', '2400:cb00::/32', '2c0f:f248::/32')
        ->not->toContain('cloudflare', 'Cloudflare');
})->with([
    'alone' => ['cloudflare', 22],
    'any letter case, beside another proxy' => [' Cloudflare , 10.0.0.1 ', 23],
    'written twice' => ['cloudflare,cloudflare', 22],
]);

test('with the cloudflare ranges, a visitor is known by the address cloudflare forwards and nobody else is let in', function () {
    Route::get('/test-client-ip', fn () => request()->ip());

    config([
        'trustedproxy.required' => true,
        'trustedproxy.proxies' => configFileWithEnv('trustedproxy.php', ['TRUSTED_PROXIES' => 'cloudflare'])['proxies'],
    ]);

    $this->withServerVariables(['REMOTE_ADDR' => '172.64.10.20', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'])
        ->get('/test-client-ip')->assertOk()->assertSee('203.0.113.9');

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '172.64.10.20'])
        ->get('/test-client-ip')->assertForbidden();
});

test('a forwarded ip is only believed from a trusted proxy', function () {
    Route::get('/test-client-ip', fn () => request()->ip());

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'])
        ->get('/test-client-ip')->assertSee('10.0.0.1');

    config(['trustedproxy.proxies' => ['10.0.0.1']]);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'])
        ->get('/test-client-ip')->assertSee('203.0.113.9');
});
