<?php

use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Monolog\Handler\NullHandler;

beforeEach(function () {
    config(['logging.channels.auth' => ['driver' => 'monolog', 'handler' => NullHandler::class]]);
    Notification::fake();
});

/**
 * Pass the HR password step for a user with email codes on, leaving the
 * session waiting at the email code challenge.
 */
function reachEmailCodeChallenge(User $user): void
{
    test()->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('email-two-factor.login'));
}

/**
 * Send one wrong email code and return the response status.
 */
function guessEmailCode(): int
{
    return test()->post(route('email-two-factor.login.store'), ['code' => 'wrong'])->status();
}

test('the sixteenth code within an hour is refused even when the minute limit allows it', function () {
    $user = User::factory()->withHrAccount()->create(['email_two_factor_enabled' => true]);
    reachEmailCodeChallenge($user);

    foreach (range(1, 3) as $batch) {
        foreach (range(1, 5) as $attempt) {
            expect(guessEmailCode())->not->toBe(429);
        }

        $this->travel(61)->seconds();
    }

    expect(guessEmailCode())->toBe(429);
});

test('one user hitting the hourly cap does not throttle another user\'s challenge', function () {
    $attacked = User::factory()->withHrAccount()->create(['email_two_factor_enabled' => true]);
    $other = User::factory()->withHrAccount()->create(['email_two_factor_enabled' => true]);
    reachEmailCodeChallenge($attacked);

    foreach (range(1, 3) as $batch) {
        foreach (range(1, 5) as $attempt) {
            guessEmailCode();
        }

        $this->travel(61)->seconds();
    }

    expect(guessEmailCode())->toBe(429);

    $this->flushSession();
    reachEmailCodeChallenge($other);

    expect(guessEmailCode())->toBe(302);
});
