<?php

use App\Actions\Fortify\RecognisedBrowsers;
use App\Models\User;
use App\Notifications\EmailLoginCode;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->freezeTime();

    $this->attemptLogin = fn (string $email, string $password, string $ip): TestResponse => $this
        ->withServerVariables(['REMOTE_ADDR' => $ip])
        ->post(route('login.store'), ['email' => $email, 'password' => $password]);

    // Sign in and out again, returning the recognition cookie the browser was handed.
    $this->recognitionAfterSigningIn = function (User $user, string $ip): string {
        $recognition = ($this->attemptLogin)($user->email, 'password', $ip)
            ->assertSessionHasNoErrors()
            ->getCookie(RecognisedBrowsers::CookieName)
            ->getValue();

        $this->post(route('logout'));

        return $recognition;
    };

    // Ten wrong passwords from ten strangers: the email is locked for everyone.
    $this->lockEmail = function (string $email, string $ipPrefix): void {
        foreach (range(1, 10) as $attempt) {
            ($this->attemptLogin)($email, 'wrong-password', "{$ipPrefix}.{$attempt}");
        }
    };
});

test('a recognised browser still signs in while strangers have its email locked', function () {
    $user = User::factory()->withHrAccount()->create();
    $recognition = ($this->recognitionAfterSigningIn)($user, '10.9.0.1');

    ($this->lockEmail)($user->email, '10.9.1');

    ($this->attemptLogin)($user->email, 'password', '10.9.2.1')
        ->assertSessionHasErrors(['email' => __('auth.throttle', ['seconds' => 900])]);
    $this->assertGuest();

    $this->withCookie(RecognisedBrowsers::CookieName, $recognition);

    ($this->attemptLogin)($user->email, 'password', '10.9.2.2')->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($user);
});

test('a recognised browser is locked out by its own 10 failed logins', function () {
    $user = User::factory()->withHrAccount()->create();
    $recognition = ($this->recognitionAfterSigningIn)($user, '10.9.3.1');

    $this->withCookie(RecognisedBrowsers::CookieName, $recognition);
    ($this->lockEmail)($user->email, '10.9.4');

    ($this->attemptLogin)($user->email, 'password', '10.9.5.1')
        ->assertSessionHasErrors(['email' => __('auth.throttle', ['seconds' => 900])]);
    $this->assertGuest();
});

test('a browser recognised for one email is still locked out of another', function () {
    $user = User::factory()->withHrAccount()->create();
    $colleague = User::factory()->withHrAccount()->create();
    $recognition = ($this->recognitionAfterSigningIn)($colleague, '10.9.6.1');

    ($this->lockEmail)($user->email, '10.9.7');
    $this->withCookie(RecognisedBrowsers::CookieName, $recognition);

    ($this->attemptLogin)($user->email, 'password', '10.9.8.1')
        ->assertSessionHasErrors(['email' => __('auth.throttle', ['seconds' => 900])]);
    $this->assertGuest();
});

test('recognition lapses 180 days after the last sign-in', function () {
    $user = User::factory()->withHrAccount()->create();
    $recognition = ($this->recognitionAfterSigningIn)($user, '10.9.9.1');

    $this->travel(181)->days();
    ($this->lockEmail)($user->email, '10.9.10');
    $this->withCookie(RecognisedBrowsers::CookieName, $recognition);

    ($this->attemptLogin)($user->email, 'password', '10.9.11.1')
        ->assertSessionHasErrors(['email' => __('auth.throttle', ['seconds' => 900])]);
    $this->assertGuest();
});

test('a made-up recognition cookie is ignored', function () {
    $user = User::factory()->withHrAccount()->create();
    $forged = json_encode([
        'id' => 'forged-browser',
        'emails' => [RecognisedBrowsers::emailFingerprint($user->email) => now()->addYear()->getTimestamp()],
    ]);

    ($this->lockEmail)($user->email, '10.9.12');
    $this->withUnencryptedCookie(RecognisedBrowsers::CookieName, $forged);

    ($this->attemptLogin)($user->email, 'password', '10.9.13.1')
        ->assertSessionHasErrors(['email' => __('auth.throttle', ['seconds' => 900])]);
    $this->assertGuest();
});

test('the right password alone does not recognise a browser that still owes the second step', function () {
    Notification::fake([EmailLoginCode::class]);
    $user = User::factory()->withHrAccount()->create(['email_two_factor_enabled' => true]);

    ($this->attemptLogin)($user->email, 'password', '10.9.14.1')
        ->assertRedirect(route('email-two-factor.login'))
        ->assertCookieMissing(RecognisedBrowsers::CookieName);
});
