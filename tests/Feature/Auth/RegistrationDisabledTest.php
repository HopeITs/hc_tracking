<?php

use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

/**
 * RegistrationTest skips itself when the feature is off, so without these the
 * removal would be invisible and could be undone unnoticed.
 */
test('the registration feature is disabled', function () {
    expect(Features::enabled(Features::registration()))->toBeFalse();
});

test('the register routes are not reachable', function () {
    expect(Route::has('register'))->toBeFalse()
        ->and(Route::has('register.store'))->toBeFalse();

    $this->get('/register')->assertNotFound();
    $this->post('/register')->assertNotFound();
});
