<?php

/**
 * Evaluate config/reverb.php with the given APP_URL and REVERB_ALLOWED_ORIGINS
 * (null removes one) and return the app's allowed origins, restoring the real
 * environment afterwards.
 *
 * @return list<string>
 */
function reverbAllowedOriginsWithEnv(?string $appUrl, ?string $allowedOrigins): array
{
    $variables = ['APP_URL' => $appUrl, 'REVERB_ALLOWED_ORIGINS' => $allowedOrigins];
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
        return (require config_path('reverb.php'))['apps']['apps'][0]['allowed_origins'];
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

test('websockets are only allowed from the app url host by default', function (?string $allowedOrigins) {
    expect(reverbAllowedOriginsWithEnv('https://tracking.example.org:8443/app/', $allowedOrigins))
        ->toBe(['tracking.example.org']);
})->with([
    'unset' => [null],
    'empty' => [''],
    'only separators' => [' , '],
]);

test('REVERB_ALLOWED_ORIGINS replaces the app url host', function (string $allowedOrigins, array $expected) {
    expect(reverbAllowedOriginsWithEnv('https://tracking.example.org', $allowedOrigins))->toBe($expected);
})->with([
    'list with a wildcard' => [' tracking.example.org , *.example.net ,', ['tracking.example.org', '*.example.net']],
    'explicit wildcard for every origin' => ['*', ['*']],
]);

test('no websocket origin is allowed without an app url or REVERB_ALLOWED_ORIGINS', function (?string $appUrl) {
    expect(reverbAllowedOriginsWithEnv($appUrl, null))->toBe([]);
})->with([
    'unset' => [null],
    'empty' => [''],
]);
