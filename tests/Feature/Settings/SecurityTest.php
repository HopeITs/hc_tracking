<?php

use App\Models\User;
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
    $response->assertSee('Two-factor authentication');
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
        ->assertDontSee('Two-factor authentication');
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
