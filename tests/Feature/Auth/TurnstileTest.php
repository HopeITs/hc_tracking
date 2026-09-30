<?php

use App\Actions\Fortify\AuthenticateWithHrAccount;
use App\Models\User;
use App\Support\Turnstile;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/*
| Cloudflare Turnstile on the login form (App\Support\Turnstile): off until
| both keys are set; once on, the HR password is only looked at after
| Cloudflare vouches for the token the widget put in the form.
*/

const TurnstileVerifyUrl = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

/**
 * Switch the human check on and fake Cloudflare's answer to a verification;
 * a null answer is a connection that fails.
 *
 * @param  array<string, mixed>|null  $answer
 */
function turnstileAnswers(?array $answer, int $status = 200): void
{
    config(['services.turnstile.site_key' => 'test-site-key', 'services.turnstile.secret_key' => 'test-secret-key']);

    Http::preventStrayRequests();
    Http::fake([TurnstileVerifyUrl => $answer === null ? Http::failedConnection() : Http::response($answer, $status)]);
}

test('the login form has no human check until both keys are set', function (?string $siteKey, ?string $secretKey) {
    config(['services.turnstile.site_key' => $siteKey, 'services.turnstile.secret_key' => $secretKey]);

    $response = $this->get(route('login'));

    $response->assertOk()->assertDontSee('cf-turnstile');

    expect($response->headers->get('Content-Security-Policy'))->not->toContain('challenges.cloudflare.com');
})->with([
    'no keys' => [null, null],
    'site key only' => ['test-site-key', ''],
    'secret key only' => ['', 'test-secret-key'],
]);

test('users sign in without a token while the human check is off', function () {
    Http::preventStrayRequests();
    $user = User::factory()->withHrAccount()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($user);
    Http::assertNothingSent();
});

test('the login form shows the widget and its page may load it once the keys are set', function () {
    config(['services.turnstile.site_key' => 'test-site-key', 'services.turnstile.secret_key' => 'test-secret-key']);

    $response = $this->get(route('login'));

    $response->assertOk()
        ->assertSee('data-sitekey="test-site-key"', false)
        ->assertSee('https://challenges.cloudflare.com/turnstile/v0/api.js', false)
        ->assertDontSee('test-secret-key');

    expect($response->headers->get('Content-Security-Policy'))
        ->toMatch('#script-src [^;]*https://challenges\.cloudflare\.com#')
        ->toMatch('#frame-src [^;]*https://challenges\.cloudflare\.com#');
});

test('only the login page may load the widget', function () {
    config(['services.turnstile.site_key' => 'test-site-key', 'services.turnstile.secret_key' => 'test-secret-key']);

    $response = $this->actingAs(User::factory()->create())->get(route('dashboard'));

    expect($response->headers->get('Content-Security-Policy'))->not->toContain('challenges.cloudflare.com');
});

test('a login with a token cloudflare vouches for signs in, verifying the token once', function () {
    turnstileAnswers(['success' => true]);
    $user = User::factory()->withHrAccount()->create();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
            Turnstile::ResponseField => 'token-from-the-widget',
        ])
        ->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($user);

    Http::assertSentCount(1);
    Http::assertSent(fn (ClientRequest $request): bool => $request->url() === TurnstileVerifyUrl
        && $request->isForm()
        && $request->data() === [
            'secret' => 'test-secret-key',
            'response' => 'token-from-the-widget',
            'remoteip' => '203.0.113.9',
        ]);
});

test('a login without a usable token is refused before cloudflare or the password is asked', function (array $extraInput) {
    turnstileAnswers(['success' => true]);
    $user = User::factory()->withHrAccount()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password', ...$extraInput])
        ->assertSessionHasErrors([Turnstile::ResponseField => __('authentication.human_check_failed')]);

    $this->assertGuest();
    Http::assertNothingSent();
})->with([
    'no token' => [[]],
    'empty token' => [[Turnstile::ResponseField => '']],
    'token that is not text' => [[Turnstile::ResponseField => ['a', 'b']]],
    'oversized token' => [[Turnstile::ResponseField => str_repeat('a', 2049)]],
]);

test('a login with a token cloudflare rejects is refused, even with the right password', function () {
    turnstileAnswers(['success' => false, 'error-codes' => ['invalid-input-response']]);
    $user = User::factory()->withHrAccount()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
        Turnstile::ResponseField => 'forged-token',
    ])->assertSessionHasErrors([Turnstile::ResponseField => __('authentication.human_check_failed')]);

    $this->assertGuest();
});

test('failed human checks count against the ip and never lock the email out', function () {
    turnstileAnswers(['success' => true]);
    $user = User::factory()->withHrAccount()->create();

    // One address each, as a botnet would: the route's own limit is per email and IP.
    foreach (range(1, AuthenticateWithHrAccount::MaxFailedLoginsPerEmail + 1) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$attempt}"])
            ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors(Turnstile::ResponseField);
    }

    expect(RateLimiter::attempts('login-failures-ip:203.0.113.1'))->toBe(1);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
            Turnstile::ResponseField => 'token-from-the-widget',
        ])
        ->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($user);
});

test('the login is let through when cloudflare gives no usable answer', function (?array $answer, int $status) {
    turnstileAnswers($answer, $status);
    $user = User::factory()->withHrAccount()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
        Turnstile::ResponseField => 'token-from-the-widget',
    ])->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($user);
    Http::assertSentCount(1);
})->with([
    'cloudflare cannot be reached' => [null, 200],
    'cloudflare is down' => [[], 503],
    'the secret key is wrong' => [['success' => false, 'error-codes' => ['invalid-input-secret']], 200],
    'cloudflare reports its own error' => [['success' => false, 'error-codes' => ['internal-error']], 200],
]);

test('a wrong password is still refused when cloudflare gives no usable answer', function () {
    turnstileAnswers([], 503);
    $user = User::factory()->withHrAccount()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'not-the-password',
        Turnstile::ResponseField => 'token-from-the-widget',
    ])->assertSessionHasErrors('password');

    $this->assertGuest();
});
