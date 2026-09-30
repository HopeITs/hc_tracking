<?php

use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->freezeTime();

    $this->attemptLogin = fn (string $email, string $password, string $ip): TestResponse => $this
        ->withServerVariables(['REMOTE_ADDR' => $ip])
        ->post(route('login.store'), ['email' => $email, 'password' => $password]);
});

test('30 failed logins from one ip lock that ip out, whatever the email', function () {
    Event::fake([Lockout::class]);
    $user = User::factory()->withHrAccount()->create();

    foreach (range(1, 30) as $attempt) {
        ($this->attemptLogin)("nobody{$attempt}@example.org", 'wrong-password', '10.0.0.1');
    }

    ($this->attemptLogin)($user->email, 'password', '10.0.0.1')
        ->assertSessionHasErrors(['email' => __('auth.throttle', ['seconds' => 900])]);
    $this->assertGuest();
    Event::assertDispatched(Lockout::class);

    ($this->attemptLogin)($user->email, 'password', '10.0.0.2')->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($user);
});

test('10 failed logins on one email from different ips lock that email', function () {
    $user = User::factory()->withHrAccount()->create();
    $colleague = User::factory()->withHrAccount()->create();

    foreach (range(1, 10) as $attempt) {
        $email = $attempt % 2 === 0 ? $user->email : Str::upper($user->email);

        ($this->attemptLogin)($email, 'wrong-password', "10.0.1.{$attempt}");
    }

    ($this->attemptLogin)($user->email, 'password', '10.0.1.99')
        ->assertSessionHasErrors(['email' => __('auth.throttle', ['seconds' => 900])]);
    $this->assertGuest();

    ($this->attemptLogin)($colleague->email, 'password', '10.0.1.99')->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($colleague);
});

test('a successful login clears the failed logins counted against that email', function () {
    $user = User::factory()->withHrAccount()->create();

    foreach (range(1, 9) as $attempt) {
        ($this->attemptLogin)($user->email, 'wrong-password', "10.0.2.{$attempt}");
    }

    ($this->attemptLogin)($user->email, 'password', '10.0.2.50')->assertSessionHasNoErrors();
    $this->post(route('logout'));

    foreach (range(1, 9) as $attempt) {
        ($this->attemptLogin)($user->email, 'wrong-password', "10.0.3.{$attempt}");
    }

    ($this->attemptLogin)($user->email, 'password', '10.0.3.50')->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($user);
});

test('a wrong password reports a failed login without the password', function () {
    Event::fake([Failed::class]);
    $user = User::factory()->withHrAccount()->create();

    ($this->attemptLogin)($user->email, 'wrong-password', '10.0.4.1')->assertSessionHasErrors('password');

    Event::assertDispatched(Failed::class, fn (Failed $event): bool => $event->guard === 'web'
        && $event->user === null
        && $event->credentials === ['email' => $user->email]);
});
