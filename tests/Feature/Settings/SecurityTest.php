<?php

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Fortify\Features;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * A password confirmation older than auth.password_timeout, so the
 * RequiresConfirmedPassword guard treats it as lapsed.
 */
function lapsedPasswordConfirmation(): int
{
    return time() - (int) config('auth.password_timeout') - 1;
}

/**
 * Store a session row the way the database session driver does.
 */
function storeSessionRow(string $id, int $userId, string $userAgent, string $ipAddress, int $lastActivity): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $userId,
        'ip_address' => $ipAddress,
        'user_agent' => $userAgent,
        'payload' => base64_encode('secret-session-payload'),
        'last_activity' => $lastActivity,
    ]);
}

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);
    Features::passkeys([
        'confirmPassword' => true,
    ]);
});

test('security settings page can be rendered', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'));

    $response->assertOk();

    $response->assertSee('Passkeys');
    $response->assertSee('No passkeys yet');
    $response->assertSee('Sign-in code by email');
    $response->assertSee('Authenticator app (optional)');
    $response->assertSee('Enable 2FA');
});

test('security settings page requires password confirmation when enabled', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('security.edit'));

    $response->assertRedirect(route('password.confirm'));
});

test('security settings page renders without two factor when feature is disabled', function () {
    config(['fortify.features' => []]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertSee('Your password is managed in the HR system.')
        ->assertDontSee('Manage your passkeys for passwordless sign-in')
        ->assertDontSee('Add a passkey to sign in without a password')
        ->assertDontSee('Authenticator app (optional)');
});

test('two factor authentication disabled when confirmation abandoned between requests', function () {
    $user = User::factory()->create();

    $user->forceFill([
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
        'two_factor_confirmed_at' => null,
    ])->save();

    $this->actingAs($user);

    $component = Livewire::test('pages::settings.security');

    $component->assertSet('twoFactorEnabled', false);

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'two_factor_secret' => null,
        'two_factor_recovery_codes' => null,
    ]);
});

test('the security page sends people to hr to change their password', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertSee('Your password is managed in the HR system.')
        ->assertDontSee('update-password-button', false)
        ->assertDontSee('New password');
});

test('switching off two factor sends a lapsed password confirmation back to be confirmed', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => lapsedPasswordConfirmation()]);

    Livewire::test('pages::settings.security')
        ->call('disable')
        ->assertRedirect(route('password.confirm'))
        ->assertSet('twoFactorEnabled', true);

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue()
        ->and(session('url.intended'))->toBe(route('security.edit'));
});

test('two factor can be switched off while the password confirmation is fresh', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

    Livewire::test('pages::settings.security')
        ->call('disable')
        ->assertNoRedirect()
        ->assertSet('twoFactorEnabled', false);

    expect($user->fresh()->two_factor_secret)->toBeNull();
});

test('a passkey is not deleted once the password confirmation has lapsed', function () {
    $user = User::factory()->create();
    $passkey = $user->passkeys()->create(['name' => 'Office laptop', 'credential_id' => 'credential-1', 'credential' => []]);

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

    $component = Livewire::test('pages::settings.security')->call('confirmDelete', $passkey->id);

    session()->put('auth.password_confirmed_at', lapsedPasswordConfirmation());

    $component->call('deletePasskey')->assertRedirect(route('password.confirm'));

    expect($passkey->fresh())->not->toBeNull();
});

test('two factor setup does not start once the password confirmation has lapsed', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => lapsedPasswordConfirmation()]);

    Livewire::test('pages::settings.two-factor-setup-modal', ['requiresConfirmation' => true])
        ->call('startTwoFactorSetup')
        ->assertRedirect(route('password.confirm'))
        ->assertSet('manualSetupKey', '');

    expect($user->fresh()->two_factor_secret)->toBeNull();
});

test('recovery codes are neither shown nor regenerated once the password confirmation has lapsed', function () {
    $user = User::factory()->withTwoFactor()->create();
    $storedCodes = $user->two_factor_recovery_codes;

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => lapsedPasswordConfirmation()]);

    Livewire::test('pages::settings.two-factor.recovery-codes')
        ->assertSet('recoveryCodes', [])
        ->call('regenerateRecoveryCodes')
        ->assertRedirect(route('password.confirm'));

    expect($user->fresh()->two_factor_recovery_codes)->toBe($storedCodes);
});

test('recovery codes are shown and regenerated while the password confirmation is fresh', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

    $component = Livewire::test('pages::settings.two-factor.recovery-codes')
        ->assertSet('recoveryCodes', ['recovery-code-1'])
        ->call('regenerateRecoveryCodes')
        ->assertNoRedirect();

    expect($component->get('recoveryCodes'))->toHaveCount(8)->not->toContain('recovery-code-1');
});

test('the browser cannot mark two factor as enabled on the security page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

    $component = Livewire::test('pages::settings.security')
        ->dispatch('two-factor-enabled')
        ->assertSet('twoFactorEnabled', false);

    expect(fn () => $component->set('twoFactorEnabled', true))->toThrow(CannotUpdateLockedPropertyException::class);
});

test('a role that requires two factor cannot switch the app off when it has no email for codes', function () {
    config(['access-control.two_factor' => ['enforced' => true, 'required_roles' => ['Super Admin', 'General Manager', 'Managers', 'Financial']]]);

    $superAdmin = User::factory()->role('Super Admin')->withTwoFactor()->create(['email' => '']);

    $this->actingAs($superAdmin)->withSession(['auth.password_confirmed_at' => time()]);

    Livewire::test('pages::settings.security')
        ->assertSee('Your role requires two-factor authentication, so it cannot be switched off.')
        ->assertDontSee('Disable 2FA')
        ->call('disable')
        ->assertSet('twoFactorEnabled', true);

    expect($superAdmin->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();
});

test('a team member can still switch two factor off while other roles require it', function () {
    config(['access-control.two_factor' => ['enforced' => true, 'required_roles' => ['Super Admin', 'General Manager', 'Managers', 'Financial']]]);

    $teamMember = User::factory()->role('Team Member')->withTwoFactor()->create();

    $this->actingAs($teamMember)->withSession(['auth.password_confirmed_at' => time()]);

    Livewire::test('pages::settings.security')
        ->assertSee('Disable 2FA')
        ->call('disable')
        ->assertSet('twoFactorEnabled', false);

    expect($teamMember->fresh()->two_factor_secret)->toBeNull();
});

test('signed-in devices lists this account\'s live sessions, newest first, marking this device', function () {
    $this->freezeTime();
    config(['session.driver' => 'database']);

    $user = User::factory()->create();
    $iphoneSafari = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

    storeSessionRow(session()->getId(), $user->id, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '10.0.0.1', now()->getTimestamp());
    storeSessionRow('phone-session', $user->id, $iphoneSafari, '10.0.0.2', now()->subMinutes(5)->getTimestamp());
    storeSessionRow('expired-session', $user->id, $iphoneSafari, '10.0.0.3', now()->subMinutes((int) config('session.lifetime') + 1)->getTimestamp());
    storeSessionRow('someone-elses-session', User::factory()->create()->id, 'Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0', '10.0.0.4', now()->getTimestamp());

    Livewire::test('pages::settings.security')
        ->assertSeeInOrder(['Chrome on Windows', 'This device', '10.0.0.1', 'Active now', 'Safari on iOS', '10.0.0.2', 'Last active 5 minutes ago'])
        ->assertDontSee('10.0.0.3')
        ->assertDontSee('Firefox on Linux')
        ->assertDontSee('10.0.0.4')
        ->assertDontSee('phone-session')
        ->assertDontSee(session()->getId())
        ->assertDontSee(base64_encode('secret-session-payload'))
        ->assertSee('Sign out other devices');
});

test('signed-in devices are hidden when sessions are not stored in the database', function () {
    config(['session.driver' => 'file']);

    $this->actingAs(User::factory()->create())->withSession(['auth.password_confirmed_at' => time()]);

    Livewire::test('pages::settings.security')
        ->assertSet('canManageSignedInDevices', false)
        ->assertDontSee('Signed-in devices')
        ->assertDontSee('Sign out other devices');
});

test('signing out other devices ends every other session of this account and rotates the remember token', function () {
    config(['session.driver' => 'database']);

    $user = User::factory()->create(['remember_token' => 'old-remember-token']);
    $windowsChrome = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

    storeSessionRow(session()->getId(), $user->id, $windowsChrome, '10.0.0.1', now()->getTimestamp());
    storeSessionRow('laptop-session', $user->id, $windowsChrome, '10.0.0.2', now()->getTimestamp());
    storeSessionRow('phone-session', $user->id, $windowsChrome, '10.0.0.3', now()->getTimestamp());
    storeSessionRow('someone-elses-session', User::factory()->create()->id, $windowsChrome, '10.0.0.4', now()->getTimestamp());

    Livewire::test('pages::settings.security')
        ->call('signOutOtherDevices')
        ->assertNoRedirect()
        ->assertSee('No other devices are signed in.');

    expect(DB::table('sessions')->pluck('id')->all())->toEqualCanonicalizing([session()->getId(), 'someone-elses-session'])
        ->and($user->fresh()->remember_token)->not->toBeIn([null, 'old-remember-token'])
        ->and(Cookie::queued(Auth::guard('web')->getRecallerName()))->toBeNull();
});

test('this device keeps its remember-me cookie, reissued for the new token, without signing in again', function () {
    Event::fake([Login::class]);
    config(['session.driver' => 'database']);

    $user = User::factory()->create(['remember_token' => 'old-remember-token']);
    $guard = Auth::guard('web');

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

    Livewire::withCookie($guard->getRecallerName(), $user->id.'|old-remember-token|fingerprint')
        ->test('pages::settings.security')
        ->call('signOutOtherDevices');

    $newToken = $user->fresh()->remember_token;

    expect($newToken)->not->toBe('old-remember-token')
        ->and(Cookie::queued($guard->getRecallerName())?->getValue())
        ->toBe($user->id.'|'.$newToken.'|'.$guard->hashPasswordForCookie($user->getAuthPassword()));

    Event::assertNotDispatched(Login::class);
});

test('other devices are not signed out once the password confirmation has lapsed', function () {
    config(['session.driver' => 'database']);

    $user = User::factory()->create(['remember_token' => 'old-remember-token']);

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => lapsedPasswordConfirmation()]);

    storeSessionRow('laptop-session', $user->id, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/140.0.0.0 Safari/537.36', '10.0.0.2', now()->getTimestamp());

    Livewire::test('pages::settings.security')
        ->call('signOutOtherDevices')
        ->assertRedirect(route('password.confirm'));

    expect(DB::table('sessions')->where('id', 'laptop-session')->exists())->toBeTrue()
        ->and($user->fresh()->remember_token)->toBe('old-remember-token');
});
