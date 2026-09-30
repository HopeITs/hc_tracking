<?php

use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Monolog\Handler\NullHandler;

/**
 * Store a session row the way the database session driver does.
 */
function storeResetSessionRow(string $id, int $userId): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $userId,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Mozilla/5.0',
        'payload' => base64_encode('secret-session-payload'),
        'last_activity' => time(),
    ]);
}

beforeEach(function () {
    config(['logging.channels.auth' => ['driver' => 'monolog', 'handler' => NullHandler::class]]);
    config(['session.driver' => 'database']);
});

test('the command clears the authenticator app, trust, sessions and remember token of that user only', function () {
    $user = User::factory()->withTwoFactor()->create(['email' => 'lost@example.org', 'email_two_factor_enabled' => true]);
    $other = User::factory()->withTwoFactor()->create();
    TrustedDevice::factory()->for($user)->count(2)->create();
    TrustedDevice::factory()->for($other)->create();
    storeResetSessionRow('lost-session', $user->id);
    storeResetSessionRow('other-session', $other->id);
    $rememberToken = $user->remember_token;

    $this->artisan('user:reset-two-factor', [
        '--email' => 'lost@example.org',
        '--no-interaction' => true,
    ])->assertSuccessful();

    $user->refresh();
    $other->refresh();

    expect($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_recovery_codes)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull()
        ->and($user->remember_token)->not->toBe($rememberToken)
        ->and($user->email_two_factor_enabled)->toBeTrue()
        ->and($other->two_factor_secret)->not->toBeNull()
        ->and($other->two_factor_confirmed_at)->not->toBeNull();

    $this->assertDatabaseMissing('trusted_devices', ['user_id' => $user->id]);
    $this->assertDatabaseCount('trusted_devices', 1);
    $this->assertDatabaseMissing('sessions', ['id' => 'lost-session']);
    $this->assertDatabaseHas('sessions', ['id' => 'other-session']);
});

test('the command resets a required role without going through the bound disable action', function () {
    config(['access-control.two_factor' => ['enforced' => true, 'required_roles' => ['Super Admin']]]);
    $this->app->bind(DisableTwoFactorAuthentication::class, fn () => throw new RuntimeException('The bound action must not be used.'));
    $superAdmin = User::factory()->role('Super Admin')->withTwoFactor()->create(['email' => 'admin@example.org']);

    $this->artisan('user:reset-two-factor', [
        '--email' => 'admin@example.org',
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect($superAdmin->refresh()->two_factor_secret)->toBeNull()
        ->and($superAdmin->two_factor_confirmed_at)->toBeNull();
});

test('an unknown email is rejected and nothing changes', function () {
    $user = User::factory()->withTwoFactor()->create();
    TrustedDevice::factory()->for($user)->create();

    $this->artisan('user:reset-two-factor', [
        '--email' => 'nobody@example.org',
        '--no-interaction' => true,
    ])->assertFailed();

    expect($user->refresh()->two_factor_secret)->not->toBeNull();
    $this->assertDatabaseCount('trusted_devices', 1);
});

test('declining the confirmation changes nothing', function () {
    $user = User::factory()->withTwoFactor()->create(['email' => 'lost@example.org']);
    TrustedDevice::factory()->for($user)->create();
    storeResetSessionRow('lost-session', $user->id);
    $rememberToken = $user->remember_token;

    $this->artisan('user:reset-two-factor', ['--email' => 'lost@example.org'])
        ->expectsConfirmation('Clear the authenticator app of lost@example.org and sign them out everywhere?', 'no')
        ->assertFailed();

    $user->refresh();

    expect($user->two_factor_secret)->not->toBeNull()
        ->and($user->two_factor_confirmed_at)->not->toBeNull()
        ->and($user->remember_token)->toBe($rememberToken);
    $this->assertDatabaseCount('trusted_devices', 1);
    $this->assertDatabaseHas('sessions', ['id' => 'lost-session']);
});
