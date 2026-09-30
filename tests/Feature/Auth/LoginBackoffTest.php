<?php

use App\Models\User;
use App\Support\BackoffThrottle;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->freezeTime();

    $this->attemptLogin = fn (string $email, string $password, string $ip): TestResponse => $this
        ->withServerVariables(['REMOTE_ADDR' => $ip])
        ->post(route('login.store'), ['email' => $email, 'password' => $password]);

    $this->burst = function (string $email, string $ipPrefix): void {
        foreach (range(1, 10) as $attempt) {
            ($this->attemptLogin)($email, 'wrong-password', "{$ipPrefix}.{$attempt}");
        }
    };
});

test('each lockout of an email lasts twice as long as the last', function () {
    $user = User::factory()->withHrAccount()->create();

    ($this->burst)($user->email, '10.1.0');
    ($this->attemptLogin)($user->email, 'password', '10.1.0.99')
        ->assertSessionHasErrors(['email' => __('auth.throttle', ['seconds' => 900])]);

    $this->travel(901)->seconds();
    ($this->burst)($user->email, '10.1.1');
    ($this->attemptLogin)($user->email, 'password', '10.1.1.99')
        ->assertSessionHasErrors(['email' => __('auth.throttle', ['seconds' => 1800])]);

    $this->travel(1801)->seconds();
    ($this->attemptLogin)($user->email, 'password', '10.1.2.1')->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($user);
});

test('a right password after a lockout resets the backoff', function () {
    $user = User::factory()->withHrAccount()->create();

    ($this->burst)($user->email, '10.2.0');
    $this->travel(901)->seconds();
    ($this->attemptLogin)($user->email, 'password', '10.2.1.1')->assertSessionHasNoErrors();
    $this->post(route('logout'));

    ($this->burst)($user->email, '10.2.2');
    ($this->attemptLogin)($user->email, 'password', '10.2.2.99')
        ->assertSessionHasErrors(['email' => __('auth.throttle', ['seconds' => 900])]);
});

test('one email locked out does not lock out a colleague', function () {
    $user = User::factory()->withHrAccount()->create();
    $colleague = User::factory()->withHrAccount()->create();

    ($this->burst)($user->email, '10.3.0');
    $this->travel(901)->seconds();
    ($this->burst)($user->email, '10.3.1');

    ($this->attemptLogin)($colleague->email, 'password', '10.3.2.1')->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($colleague);
});

test('the backoff delay doubles up to the cap', function () {
    expect(BackoffThrottle::delaySeconds(1, 900, 21600))->toBe(900)
        ->and(BackoffThrottle::delaySeconds(2, 900, 21600))->toBe(1800)
        ->and(BackoffThrottle::delaySeconds(3, 900, 21600))->toBe(3600)
        ->and(BackoffThrottle::delaySeconds(5, 900, 21600))->toBe(14400)
        ->and(BackoffThrottle::delaySeconds(6, 900, 21600))->toBe(21600)
        ->and(BackoffThrottle::delaySeconds(500, 900, 21600))->toBe(21600);
});

test('failed password confirmations back off too', function () {
    $user = User::factory()->withHrAccount('hr-secret')->create();
    $this->actingAs($user);

    foreach ([1, 2] as $burst) {
        foreach (range(1, 10) as $attempt) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.4.{$burst}.{$attempt}"])
                ->post(route('password.confirm.store'), ['password' => 'wrong-password']);
        }

        $this->withServerVariables(['REMOTE_ADDR' => "10.4.{$burst}.99"])
            ->post(route('password.confirm.store'), ['password' => 'hr-secret'])
            ->assertSessionHasErrors(['password' => __('auth.confirm_throttle', ['seconds' => 900 * $burst])]);

        $this->travel(901 * $burst)->seconds();
    }
});
