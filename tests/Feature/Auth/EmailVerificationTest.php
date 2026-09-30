<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

/**
 * Email addresses come from HR, so verification is switched off. Without
 * these, re-enabling it (and its resend endpoint) would go unnoticed.
 */
test('the email verification feature is disabled', function () {
    expect(Features::enabled(Features::emailVerification()))->toBeFalse();
});

test('the email verification routes are not registered', function () {
    expect(Route::has('verification.notice'))->toBeFalse()
        ->and(Route::has('verification.verify'))->toBeFalse()
        ->and(Route::has('verification.send'))->toBeFalse();
});

test('a signed-in user cannot reach the verification pages or trigger verification emails', function () {
    $this->actingAs(User::factory()->unverified()->create());

    $this->get('/email/verify')->assertNotFound();
    $this->get('/email/verify/1/'.sha1('someone@example.org'))->assertNotFound();
    $this->post('/email/verification-notification')->assertNotFound();
});
