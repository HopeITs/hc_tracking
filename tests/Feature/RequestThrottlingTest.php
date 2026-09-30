<?php

use App\Http\Middleware\ThrottleRequestsWithoutSession;
use App\Models\User;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->freezeTime();

    $this->visit = fn (string $ip): TestResponse => $this
        ->withServerVariables(['REMOTE_ADDR' => $ip])
        ->get(route('home'));
});

test('an ip is refused after 120 requests a minute that carry no session cookie', function () {
    foreach (range(1, ThrottleRequestsWithoutSession::MaxPerMinute) as $request) {
        ($this->visit)('10.30.0.1')->assertRedirect(route('login'));
    }

    ($this->visit)('10.30.0.1')
        ->assertTooManyRequests()
        ->assertHeader('Retry-After', '60')
        ->assertCookieMissing(config('session.cookie'));
    ($this->visit)('10.30.0.2')->assertRedirect(route('login'));
});

test('a made-up session cookie counts as no session cookie', function () {
    $this->withUnencryptedCookie(config('session.cookie'), 'made-up-session-id');

    foreach (range(1, ThrottleRequestsWithoutSession::MaxPerMinute) as $request) {
        ($this->visit)('10.30.1.1');
    }

    ($this->visit)('10.30.1.1')->assertTooManyRequests();
});

test('a browser holding an issued session cookie is not counted as a new visitor', function () {
    foreach (range(1, ThrottleRequestsWithoutSession::MaxPerMinute) as $request) {
        ($this->visit)('10.30.2.1');
    }

    $this->withCookie(config('session.cookie'), 'issued-session-id');

    ($this->visit)('10.30.2.1')->assertRedirect(route('login'));
});

test('signed-out requests are limited to 300 a minute per ip, signed-in ones are not', function () {
    $user = User::factory()->create();
    $this->withCookie(config('session.cookie'), 'issued-session-id');

    foreach (range(1, 300) as $request) {
        ($this->visit)('10.30.3.1');
    }

    ($this->visit)('10.30.3.1')->assertTooManyRequests();
    ($this->visit)('10.30.3.2')->assertRedirect(route('login'));

    $this->actingAs($user);

    ($this->visit)('10.30.3.1')->assertRedirect(route('dashboard'));
});

test('a user is limited to 180 livewire requests a minute', function () {
    $this->actingAs(User::factory()->create())->withCookie(config('session.cookie'), 'issued-session-id');

    foreach (range(1, 180) as $request) {
        expect($this->post(route('livewire.update'))->status())->not->toBe(429);
    }

    $this->post(route('livewire.update'))->assertTooManyRequests();
});

test('a user is limited to 20 file uploads a minute', function () {
    $this->actingAs(User::factory()->create());

    foreach (range(1, 20) as $request) {
        expect($this->post(route('livewire.upload-file'))->status())->not->toBe(429);
    }

    $this->post(route('livewire.upload-file'))->assertTooManyRequests();
});
