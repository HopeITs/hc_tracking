<?php

use App\Events\NotificationPushed;
use App\Listeners\AlertOnLockoutSurge;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Notifications\LockoutSurge;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Monolog\Handler\NullHandler;

beforeEach(function () {
    config(['logging.channels.auth' => ['driver' => 'monolog', 'handler' => NullHandler::class]]);
});

/**
 * Fire the given number of refused sign-in attempts from one address.
 */
function refuseSignIns(int $times, string $ip = '203.0.113.9'): void
{
    $request = Request::create('/login', 'POST', ['email' => 'nobody@example.org'], server: ['REMOTE_ADDR' => $ip]);

    foreach (range(1, $times) as $attempt) {
        event(new Lockout($request));
    }
}

test('24 refused sign-ins alert nobody', function () {
    Notification::fake();
    userWithRole('Super Admin');

    refuseSignIns(AlertOnLockoutSurge::Threshold - 1);

    Notification::assertNothingSent();
});

test('the 25th refused sign-in alerts every administrator once, in the inbox and by email', function () {
    Notification::fake();
    $administrator = userWithRole('Super Admin');
    $otherAdministrator = userWithRole('Super Admin');
    $manager = userWithRole('General Manager');

    refuseSignIns(AlertOnLockoutSurge::Threshold + 10);

    foreach ([$administrator, $otherAdministrator] as $recipient) {
        Notification::assertSentToTimes($recipient, AppNotification::class, 1);
        Notification::assertSentToTimes($recipient, LockoutSurge::class, 1);
    }

    Notification::assertNothingSentTo($manager);
    Notification::assertSentTo($administrator, LockoutSurge::class, fn (LockoutSurge $notification): bool => $notification->attempts === 25
        && $notification->minutes === 15
        && $notification->latestIpAddress === '203.0.113.9');
});

test('a new surge after the window alerts again', function () {
    Notification::fake();
    $administrator = userWithRole('Super Admin');

    refuseSignIns(AlertOnLockoutSurge::Threshold);
    $this->travel(AlertOnLockoutSurge::WindowMinutes + 1)->minutes();
    refuseSignIns(AlertOnLockoutSurge::Threshold - 1);

    Notification::assertSentToTimes($administrator, LockoutSurge::class, 1);

    refuseSignIns(1);

    Notification::assertSentToTimes($administrator, LockoutSurge::class, 2);
});

test('an administrator without an email is alerted in the inbox only', function () {
    Notification::fake();
    $administrator = userWithRole('Super Admin');
    $administrator->forceFill(['hr_user_id' => 999_999])->save();

    refuseSignIns(AlertOnLockoutSurge::Threshold);

    expect($administrator->fresh()->email)->toBe('');
    Notification::assertSentToTimes($administrator, AppNotification::class, 1);
    Notification::assertNotSentTo($administrator, LockoutSurge::class);
});

test('the inbox alert is critical, says what happened and opens the activity log', function () {
    Event::fake([NotificationPushed::class]);
    $administrator = userWithRole('Super Admin');

    refuseSignIns(AlertOnLockoutSurge::Threshold);

    expect(sentencesFor($administrator))->toBe([
        '25 sign-in attempts were blocked in the last 15 minutes. Someone may be guessing passwords: tell IT.',
    ]);

    Event::assertDispatched(NotificationPushed::class, fn (NotificationPushed $event): bool => $event->userId === $administrator->id
        && $event->payload['priority'] === 'critical');

    $this->actingAs($administrator)
        ->get(route('notifications.open', $administrator->notifications()->value('id')))
        ->assertRedirect(route('activity.index'));
});

test('the english email names the app, the count and the latest address', function () {
    $administrator = User::factory()->create();
    app()->setLocale('en');

    $mail = (new LockoutSurge(25, 15, '203.0.113.9', CarbonImmutable::now()))->toMail($administrator);

    expect($mail->subject)->toBe('Many blocked sign-in attempts on '.config('app.name'))
        ->and($mail->introLines)->toContain(
            '25 sign-in attempts were blocked in the last 15 minutes because too many passwords had already been wrong.',
            'Latest IP address: 203.0.113.9',
        )
        ->and((string) $mail->render())->toContain('dir="ltr"');
});
