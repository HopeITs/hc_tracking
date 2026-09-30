<?php

use App\Http\UserAgent;
use App\Models\User;
use App\Notifications\EmailLoginCode;
use App\Notifications\SecondStepFailed;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Monolog\Handler\NullHandler;

beforeEach(function () {
    config(['logging.channels.auth' => ['driver' => 'monolog', 'handler' => NullHandler::class]]);
    Notification::fake();
});

/**
 * Fire the given number of failed second-step events for the user.
 */
function failSecondStep(User $user, int $times): void
{
    foreach (range(1, $times) as $attempt) {
        event(new TwoFactorAuthenticationFailed($user));
    }
}

test('two failed codes send nothing', function () {
    $user = User::factory()->withHrAccount()->create();

    failSecondStep($user, 2);

    Notification::assertNotSentTo($user, SecondStepFailed::class);
});

test('the third wrong email code alerts the user once, with the device and ip', function () {
    $user = User::factory()->withHrAccount()->create(['email_two_factor_enabled' => true]);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->withHeaders(['User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:125.0) Gecko/20100101 Firefox/125.0'])
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('email-two-factor.login'));

    $code = null;
    Notification::assertSentTo($user, EmailLoginCode::class, function (EmailLoginCode $notification) use (&$code): bool {
        $code = $notification->code;

        return true;
    });
    $wrongCode = $code === '000000' ? '111111' : '000000';

    foreach (range(1, 3) as $attempt) {
        $this->post(route('email-two-factor.login.store'), ['code' => $wrongCode])
            ->assertSessionHasErrors('code');
    }

    Notification::assertSentToTimes($user, SecondStepFailed::class, 1);
    Notification::assertSentTo($user, SecondStepFailed::class, function (SecondStepFailed $notification) use ($user): bool {
        $mail = $notification->toMail($user);

        return $notification->ipAddress === '203.0.113.9'
            && in_array('Device: Firefox on Linux', $mail->introLines, true)
            && $mail->actionUrl === route('security.edit');
    });
});

test('further failures in the same window send nothing more', function () {
    $user = User::factory()->withHrAccount()->create();

    failSecondStep($user, 5);

    Notification::assertSentToTimes($user, SecondStepFailed::class, 1);
});

test('a new batch of failures after the window alerts again', function () {
    $user = User::factory()->withHrAccount()->create();

    failSecondStep($user, 3);
    $this->travel(16)->minutes();
    failSecondStep($user, 2);

    Notification::assertSentToTimes($user, SecondStepFailed::class, 1);

    failSecondStep($user, 1);

    Notification::assertSentToTimes($user, SecondStepFailed::class, 2);
});

test('a user without an email is not alerted', function () {
    $user = User::factory()->create(['hr_user_id' => 999_999]);

    failSecondStep($user, 3);

    expect($user->email)->toBe('');
    Notification::assertNotSentTo($user, SecondStepFailed::class);
});

test('the english alert names the app in its subject', function () {
    $user = User::factory()->create();
    app()->setLocale('en');

    $mail = (new SecondStepFailed(UserAgent::parse('curl/8.7.1'), '203.0.113.9', CarbonImmutable::now()))->toMail($user);

    expect($mail->subject)->toBe('Someone entered your password on '.config('app.name'))
        ->and($mail->introLines)->toContain('Device: an unrecognised device')
        ->and((string) $mail->render())->toContain('dir="ltr"');
});
