<?php

test('reverb only listens on the loopback interface by default', function () {
    expect(config('reverb.servers.reverb.host'))->toBe('127.0.0.1');
});

test('reverb bounds what a client holding the public key can do', function () {
    $app = config('reverb.apps.apps.0');

    expect($app['max_connections'])->toBe(1000)
        ->and($app['accept_client_events_from'])->toBe('none')
        ->and($app['rate_limiting'])->toBe([
            'enabled' => true,
            'max_attempts' => 60,
            'decay_seconds' => 60,
            'terminate_on_limit' => true,
        ]);
});
