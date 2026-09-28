<?php

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Log;

/**
 * Re-runs the provider's registration as if the app had started in this
 * environment with this APP_DEBUG value.
 */
function registerAppProviderAs(string $environment, bool $debug): void
{
    app()->detectEnvironment(fn (): string => $environment);
    config(['app.debug' => $debug]);

    (new AppServiceProvider(app()))->register();
}

test('production forces debug mode off and logs the misconfiguration once', function () {
    Log::shouldReceive('critical')->once()->withArgs(fn (string $message): bool => str_contains($message, 'APP_DEBUG=true'));

    registerAppProviderAs('production', true);
    registerAppProviderAs('production', true);

    expect(config('app.debug'))->toBeFalse();
});

test('debug mode is left as configured and nothing is logged', function (string $environment, bool $debug) {
    Log::shouldReceive('critical')->never();

    registerAppProviderAs($environment, $debug);

    expect(config('app.debug'))->toBe($debug);
})->with([
    'local with debug on' => ['local', true],
    'production with debug off' => ['production', false],
]);
