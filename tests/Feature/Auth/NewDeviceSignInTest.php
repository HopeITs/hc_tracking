<?php

use App\Http\UserAgent;
use App\Models\KnownDevice;
use App\Models\User;
use App\Notifications\NewDeviceSignIn;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Monolog\Handler\NullHandler;

beforeEach(function () {
    config(['logging.channels.auth' => ['driver' => 'monolog', 'handler' => NullHandler::class]]);
});

/**
 * A real User-Agent header for the devices these tests sign in from.
 */
function deviceUserAgent(string $device): string
{
    return match ($device) {
        'chrome-windows' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'chrome-windows-updated' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.6422.60 Safari/537.36',
        'firefox-linux' => 'Mozilla/5.0 (X11; Linux x86_64; rv:125.0) Gecko/20100101 Firefox/125.0',
        'curl' => 'curl/8.7.1',
    };
}

/**
 * Sign in through the login form from a given browser and IP, then sign out
 * again so the next sign-in starts as a guest.
 */
function signInFrom(User $user, string $userAgent, string $ip = '10.0.0.1'): void
{
    test()->withHeaders(['User-Agent' => $userAgent])
        ->withServerVariables(['REMOTE_ADDR' => $ip])
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasNoErrors();

    Auth::logout();
}

test('the first sign-in records the device as a baseline without an email', function () {
    Notification::fake();
    $user = User::factory()->withHrAccount()->create();

    signInFrom($user, deviceUserAgent('chrome-windows'));

    $device = KnownDevice::query()->sole();
    expect($device->user_id)->toBe($user->id)
        ->and($device->label)->toBe('Chrome on Windows')
        ->and($device->last_ip)->toBe('10.0.0.1');
    Notification::assertNotSentTo($user, NewDeviceSignIn::class);
});

test('the stored label is english whatever language the sign-in happens in', function () {
    Notification::fake();
    $user = User::factory()->withHrAccount()->create(['locale' => 'ar']);

    $this->withSession(['locale' => 'ar']);
    signInFrom($user, deviceUserAgent('chrome-windows'));

    expect(KnownDevice::query()->sole()->label)->toBe('Chrome on Windows');
});

test('signing in again from a known device only refreshes when and where it was last seen', function () {
    Notification::fake();
    $user = User::factory()->withHrAccount()->create();

    signInFrom($user, deviceUserAgent('chrome-windows'), '10.0.0.1');
    $this->travel(2)->hours();
    signInFrom($user, deviceUserAgent('chrome-windows-updated'), '10.0.0.2');

    $device = KnownDevice::query()->sole();
    expect($device->last_ip)->toBe('10.0.0.2')
        ->and($device->last_seen_at->toDateTimeString())->toBe(now()->toDateTimeString());
    Notification::assertNotSentTo($user, NewDeviceSignIn::class);
});

test('signing in from a new browser emails the user the device and ip', function () {
    Notification::fake();
    $user = User::factory()->withHrAccount()->create();

    signInFrom($user, deviceUserAgent('chrome-windows'));
    signInFrom($user, deviceUserAgent('firefox-linux'), '203.0.113.9');

    expect(KnownDevice::query()->where('user_id', $user->id)->count())->toBe(2);
    Notification::assertSentTo($user, NewDeviceSignIn::class, function (NewDeviceSignIn $notification) use ($user): bool {
        $mail = $notification->toMail($user);

        return $notification->ipAddress === '203.0.113.9'
            && in_array('Device: Firefox on Linux', $mail->introLines, true)
            && in_array('IP address: 203.0.113.9', $mail->introLines, true)
            && $mail->actionUrl === route('security.edit');
    });
    Notification::assertSentToTimes($user, NewDeviceSignIn::class, 1);
});

test('an unrecognised browser and platform read as an unrecognised device in the email', function (string $locale, string $expected) {
    $user = User::factory()->create();
    app()->setLocale($locale);

    $mail = (new NewDeviceSignIn(UserAgent::parse(deviceUserAgent('curl')), '203.0.113.9', CarbonImmutable::now()))->toMail($user);

    expect($mail->introLines)->toContain($expected);
})->with([
    'english' => ['en', 'Device: an unrecognised device'],
    'arabic' => ['ar', 'الجهاز: جهاز غير معروف'],
]);

test('a half-recognised device names the part that is known', function () {
    $user = User::factory()->create();

    $unknownPlatform = (new NewDeviceSignIn(new UserAgent('Firefox', UserAgent::Unknown), '203.0.113.9', CarbonImmutable::now()))->toMail($user);
    $unknownBrowser = (new NewDeviceSignIn(new UserAgent(UserAgent::Unknown, 'Windows'), '203.0.113.9', CarbonImmutable::now()))->toMail($user);

    expect($unknownPlatform->introLines)->toContain('Device: Firefox on an unrecognised system')
        ->and($unknownBrowser->introLines)->toContain('Device: an unrecognised browser on Windows');
});

test('the new sign-in email is written in the user\'s language, right to left for arabic', function () {
    Notification::fake();
    $user = User::factory()->withHrAccount()->create(['locale' => 'ar']);
    KnownDevice::factory()->for($user)->forUserAgent(deviceUserAgent('chrome-windows'))->create();

    signInFrom($user, deviceUserAgent('firefox-linux'));

    Notification::assertSentTo($user, NewDeviceSignIn::class, function (NewDeviceSignIn $notification) use ($user): bool {
        app()->setLocale('ar');
        $mail = $notification->toMail($user);
        $html = (string) $mail->render();

        return $notification->locale === 'ar'
            && in_array('الجهاز: Firefox على Linux', $mail->introLines, true)
            && str_contains($html, 'dir="rtl"')
            && str_contains($html, 'text-align: right !important')
            && str_contains($html, 'إذا واجهت مشكلة في النقر على زر')
            && str_contains($html, 'جميع الحقوق محفوظة.')
            && str_contains($html, 'مع التحية،');
    });
});

test('the english email stays left to right', function () {
    $user = User::factory()->create();
    app()->setLocale('en');

    $html = (string) (new NewDeviceSignIn(UserAgent::parse(deviceUserAgent('firefox-linux')), '203.0.113.9', CarbonImmutable::now()))
        ->toMail($user)
        ->render();

    expect($html)->toContain('dir="ltr"')
        ->not->toContain('text-align: right !important')
        ->toContain('All rights reserved.');
});

test('a user without an email is not mailed about a new device', function () {
    Notification::fake();
    $user = User::factory()->create(['hr_user_id' => 999_999]);
    KnownDevice::factory()->for($user)->forUserAgent(deviceUserAgent('chrome-windows'))->create();
    $this->app['request']->headers->set('User-Agent', deviceUserAgent('firefox-linux'));

    event(new Login('web', $user, false));

    expect($user->email)->toBe('')
        ->and(KnownDevice::query()->where('user_id', $user->id)->count())->toBe(2);
    Notification::assertNotSentTo($user, NewDeviceSignIn::class);
});

test('a failing new sign-in email is reported and does not stop the sign-in', function () {
    Exceptions::fake();
    $user = User::factory()->withHrAccount()->create();
    KnownDevice::factory()->for($user)->forUserAgent(deviceUserAgent('chrome-windows'))->create();
    Notification::shouldReceive('send')->andThrow(new RuntimeException('Mail server is down'));

    $this->withHeaders(['User-Agent' => deviceUserAgent('firefox-linux')])
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    Exceptions::assertReported(RuntimeException::class);
});

test('deleting a user deletes their known devices', function () {
    $device = KnownDevice::factory()->create();

    $device->user->delete();

    expect(KnownDevice::query()->whereKey($device->id)->exists())->toBeFalse();
});
