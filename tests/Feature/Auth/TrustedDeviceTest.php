<?php

use App\Actions\Fortify\TrustedDevices;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Notifications\EmailLoginCode;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;
use Livewire\Livewire;

/**
 * Sign in with the password and the emailed code, ticking "don't ask again"
 * or not, and return the trusted-device cookie the browser was given.
 */
function signInWithEmailCode(User $user, bool $trustDevice): ?string
{
    test()->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('email-two-factor.login'));

    $code = null;

    Notification::assertSentTo($user, EmailLoginCode::class, function (EmailLoginCode $notification) use (&$code): bool {
        $code = $notification->code;

        return true;
    });

    $response = test()->post(route('email-two-factor.login.store'), array_filter([
        'code' => $code,
        TrustedDevices::RememberField => $trustDevice ? '1' : null,
    ]));

    test()->assertAuthenticatedAs($user);

    return $response->getCookie(TrustedDevices::cookieName($user))?->getValue();
}

beforeEach(function () {
    Notification::fake();
});

test('a trusted browser signs in with the password alone', function () {
    $user = User::factory()->withHrAccount()->create(['email_two_factor_enabled' => true]);

    $token = signInWithEmailCode($user, trustDevice: true);

    expect($token)->not->toBeNull()
        ->and($user->trustedDevices()->sole()->token_hash)->toBe(hash('sha256', $token));

    auth()->logout();
    Notification::fake();

    $this->withCookie(TrustedDevices::cookieName($user), $token)
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
    Notification::assertNotSentTo($user, EmailLoginCode::class);
});

test('without the box ticked the browser is not trusted', function () {
    $user = User::factory()->withHrAccount()->create(['email_two_factor_enabled' => true]);

    expect(signInWithEmailCode($user, trustDevice: false))->toBeNull()
        ->and($user->trustedDevices()->exists())->toBeFalse();
});

test('a trusted browser still needs the right password', function () {
    $user = User::factory()->withHrAccount()->create(['email_two_factor_enabled' => true]);
    $token = signInWithEmailCode($user, trustDevice: true);
    auth()->logout();

    $this->withCookie(TrustedDevices::cookieName($user), $token)
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertSessionHasErrors('password');

    $this->assertGuest();
});

test('an expired, unknown or other user\'s cookie still asks for a code', function () {
    $user = User::factory()->withHrAccount()->create(['email_two_factor_enabled' => true]);
    $other = User::factory()->withHrAccount()->create(['email_two_factor_enabled' => true]);
    $otherToken = signInWithEmailCode($other, trustDevice: true);
    auth()->logout();

    TrustedDevice::factory()->for($user)->expired()->create(['token_hash' => hash('sha256', 'expired-token')]);

    foreach (['expired-token', 'made-up-token', $otherToken] as $token) {
        $this->withCookie(TrustedDevices::cookieName($user), $token)
            ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('email-two-factor.login'));

        $this->assertGuest();
    }
});

test('ticking the box on the authenticator app challenge trusts the browser too', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    $user = User::factory()->withTwoFactor()->withHrAccount()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));

    $this->post(route('two-factor.login.store'), [
        'recovery_code' => 'recovery-code-1',
        TrustedDevices::RememberField => '1',
    ])->assertCookie(TrustedDevices::cookieName($user));

    $this->assertAuthenticatedAs($user);
    expect($user->trustedDevices()->count())->toBe(1);
});

test('forgetting trusted devices makes every browser ask again', function () {
    $user = User::factory()->create();
    TrustedDevice::factory()->for($user)->count(2)->create();

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

    Livewire::test('pages::settings.security')
        ->assertSee(__('settings.forget_trusted_devices'))
        ->call('forgetTrustedDevices')
        ->assertSee(__('settings.no_trusted_devices'));

    expect($user->trustedDevices()->exists())->toBeFalse();
});

test('forgetting trusted devices needs a fresh password confirmation', function () {
    $user = User::factory()->create();
    TrustedDevice::factory()->for($user)->create();

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time() - (int) config('auth.password_timeout') - 1]);

    Livewire::test('pages::settings.security')
        ->call('forgetTrustedDevices')
        ->assertRedirect(route('password.confirm'));

    expect($user->trustedDevices()->count())->toBe(1);
});

test('expired trust is pruned', function () {
    TrustedDevice::factory()->expired()->create();
    $live = TrustedDevice::factory()->create();

    $this->artisan('model:prune', ['--model' => [TrustedDevice::class]]);

    expect(TrustedDevice::query()->pluck('id')->all())->toBe([$live->id]);
});

test('the user menu links to the security page', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-test="security-menu-item"', false)
        ->assertSee(route('security.edit'));
});

test('switching off the authenticator app forgets every trusted browser of that user', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
    Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]);

    $user = User::factory()->withTwoFactor()->create();
    TrustedDevice::factory()->for($user)->count(2)->create();
    $otherDevice = TrustedDevice::factory()->create();

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

    Livewire::test('pages::settings.security')->call('disable');

    expect($user->fresh()->two_factor_secret)->toBeNull()
        ->and($user->trustedDevices()->exists())->toBeFalse()
        ->and(TrustedDevice::query()->pluck('id')->all())->toBe([$otherDevice->id]);
});

test('clearing an unfinished app set-up keeps the trusted browsers', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
    Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]);

    $user = User::factory()->withTwoFactor()->create(['two_factor_confirmed_at' => null, 'email_two_factor_enabled' => true]);
    TrustedDevice::factory()->for($user)->create();

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

    Livewire::test('pages::settings.security');

    expect($user->fresh()->two_factor_secret)->toBeNull()
        ->and($user->trustedDevices()->count())->toBe(1);
});

test('making new recovery codes forgets every trusted browser', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    $user = User::factory()->withTwoFactor()->create();
    TrustedDevice::factory()->for($user)->count(2)->create();

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

    Livewire::test('pages::settings.two-factor.recovery-codes')->call('regenerateRecoveryCodes');

    expect($user->trustedDevices()->exists())->toBeFalse();
});
