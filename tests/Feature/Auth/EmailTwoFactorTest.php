<?php

use App\Actions\Fortify\EmailLoginCodes;
use App\Models\User;
use App\Notifications\EmailLoginCode;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Sleep;
use Laravel\Fortify\Features;
use Livewire\Livewire;

/**
 * Log in with the HR password and return the code that was emailed.
 */
function passwordStepEmailsCode(User $user): string
{
    test()->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('email-two-factor.login'));

    $code = null;

    Notification::assertSentTo($user, EmailLoginCode::class, function (EmailLoginCode $notification) use (&$code): bool {
        $code = $notification->code;

        return true;
    });

    return $code;
}

beforeEach(function () {
    Notification::fake();
});

test('new users start with email codes off and sign in with the password only', function () {
    $user = User::factory()->withHrAccount()->create();

    expect($user->fresh()->email_two_factor_enabled)->toBeFalse();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
    Notification::assertNotSentTo($user, EmailLoginCode::class);
});

test('with email codes on, the right code finishes signing in', function () {
    $user = User::factory()->withHrAccount()->create(['email_two_factor_enabled' => true]);

    $code = passwordStepEmailsCode($user);

    $this->assertGuest();
    $this->get(route('email-two-factor.login'))->assertOk();

    $this->post(route('email-two-factor.login.store'), ['code' => $code])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

test('a wrong code is refused and a used code cannot be replayed', function () {
    $user = User::factory()->withHrAccount()->create(['email_two_factor_enabled' => true]);

    $code = passwordStepEmailsCode($user);
    $wrongCode = $code === '000000' ? '111111' : '000000';

    $this->post(route('email-two-factor.login.store'), ['code' => $wrongCode])
        ->assertSessionHasErrors('code');
    $this->assertGuest();

    $this->post(route('email-two-factor.login.store'), ['code' => $code]);
    $this->assertAuthenticatedAs($user);

    auth()->logout();
    $this->withSession(['login.id' => $user->id, 'login.email_code' => true])
        ->post(route('email-two-factor.login.store'), ['code' => $code])
        ->assertSessionHasErrors('code');
    $this->assertGuest();
});

test('the code stops working after too many wrong guesses', function () {
    $user = User::factory()->withHrAccount()->create(['email_two_factor_enabled' => true]);

    $code = passwordStepEmailsCode($user);
    $wrongCode = $code === '000000' ? '111111' : '000000';

    foreach (range(1, 5) as $attempt) {
        $this->post(route('email-two-factor.login.store'), ['code' => $wrongCode]);
    }

    $this->withoutMiddleware(ThrottleRequests::class)
        ->post(route('email-two-factor.login.store'), ['code' => $code])
        ->assertSessionHasErrors('code');
    $this->assertGuest();
});

test('resending the code is limited per login attempt, whatever the ip', function () {
    $this->freezeTime();
    $user = User::factory()->withHrAccount()->create(['email_two_factor_enabled' => true]);
    passwordStepEmailsCode($user);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.5.1'])->post(route('email-two-factor.resend'));
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.5.2'])->post(route('email-two-factor.resend'));

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.5.3'])
        ->post(route('email-two-factor.resend'))
        ->assertStatus(429);
});

test('resending the code is also limited per ip, across different logins', function () {
    $this->freezeTime();
    $attemptsFromOneIp = fn (User $user) => $this
        ->withServerVariables(['REMOTE_ADDR' => '10.0.6.1'])
        ->post(route('email-two-factor.resend'));

    foreach (range(1, 6) as $attempt) {
        $user = User::factory()->withHrAccount()->create(['email_two_factor_enabled' => true]);
        passwordStepEmailsCode($user);

        $response = ($attemptsFromOneIp)($user);

        if ($attempt <= 5) {
            $response->assertStatus(302);
        } else {
            $response->assertStatus(429);
        }

        auth()->logout();
        $this->flushSession();
    }
});

test('the challenge page needs a password step first', function () {
    $this->get(route('email-two-factor.login'))->assertRedirect(route('login'));
    $this->post(route('email-two-factor.login.store'), ['code' => '123456'])->assertRedirect(route('login'));
});

test('an authenticator app takes priority over email codes', function () {
    $user = User::factory()->withTwoFactor()->withHrAccount()->create(['email_two_factor_enabled' => true]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('two-factor.login'));

    Notification::assertNotSentTo($user, EmailLoginCode::class);
});

test('anyone can turn email codes on and off from the security page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

    Livewire::test('pages::settings.security')
        ->assertSet('emailTwoFactorEnabled', false)
        ->call('setEmailTwoFactor', true)
        ->assertSet('emailTwoFactorEnabled', true);

    expect($user->fresh()->email_two_factor_enabled)->toBeTrue();

    Livewire::test('pages::settings.security')
        ->call('setEmailTwoFactor', false)
        ->assertSet('emailTwoFactorEnabled', false);

    expect($user->fresh()->email_two_factor_enabled)->toBeFalse();
});

test('changing email codes needs a fresh password confirmation', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time() - (int) config('auth.password_timeout') - 1]);

    Livewire::test('pages::settings.security')
        ->call('setEmailTwoFactor', true)
        ->assertRedirect(route('password.confirm'));

    expect($user->fresh()->email_two_factor_enabled)->toBeFalse();
});

test('roles that must use two factor always get an email code, even with their setting off', function (string $role) {
    config(['access-control.two_factor' => ['enforced' => true, 'required_roles' => ['Super Admin', 'General Manager', 'Managers', 'Financial']]]);
    $this->seed(RolesAndPermissionsSeeder::class);

    $user = User::factory()->role($role)->withHrAccount()->create();

    expect($user->email_two_factor_enabled)->toBeFalse();

    $code = passwordStepEmailsCode($user);

    $this->post(route('email-two-factor.login.store'), ['code' => $code]);
    $this->assertAuthenticatedAs($user);
})->with(['Super Admin', 'General Manager', 'Managers', 'Financial']);

test('other roles are not asked for an email code while enforcement is on', function () {
    config(['access-control.two_factor' => ['enforced' => true, 'required_roles' => ['Super Admin', 'General Manager', 'Managers', 'Financial']]]);
    $this->seed(RolesAndPermissionsSeeder::class);

    $user = User::factory()->role('Team Member')->withHrAccount()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard'));

    Notification::assertNotSentTo($user, EmailLoginCode::class);
});

test('a role that requires two factor cannot turn email codes off', function () {
    config(['access-control.two_factor' => ['enforced' => true, 'required_roles' => ['Super Admin', 'General Manager', 'Managers', 'Financial']]]);
    $this->seed(RolesAndPermissionsSeeder::class);

    $superAdmin = User::factory()->role('Super Admin')->create(['email_two_factor_enabled' => true]);

    $this->actingAs($superAdmin)->withSession(['auth.password_confirmed_at' => time()]);

    Livewire::test('pages::settings.security')
        ->assertSet('emailTwoFactorEnabled', true)
        ->assertSee(__('settings.email_2fa_required'))
        ->assertDontSee(__('settings.email_2fa_disable'))
        ->call('setEmailTwoFactor', false)
        ->assertSet('emailTwoFactorEnabled', true);

    expect($superAdmin->fresh()->email_two_factor_enabled)->toBeTrue();
});

test('a role that requires two factor can use the authenticator app instead of email codes', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
    config(['access-control.two_factor' => ['enforced' => true, 'required_roles' => ['Super Admin', 'General Manager', 'Managers', 'Financial']]]);
    $this->seed(RolesAndPermissionsSeeder::class);

    $superAdmin = User::factory()->role('Super Admin')->withTwoFactor()->withHrAccount()->create();

    $this->post(route('login.store'), ['email' => $superAdmin->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));

    Notification::assertNotSentTo($superAdmin, EmailLoginCode::class);
});

test('when the code email cannot be sent, sign-in stops with a clear message', function () {
    $user = User::factory()->withHrAccount()->create(['email_two_factor_enabled' => true]);

    Notification::shouldReceive('sendNow')->andThrow(new RuntimeException('SMTP down'));

    $this->from(route('login'))
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => __('authentication.email_code_not_sent')])
        ->assertSessionMissing('login.id');

    $this->assertGuest();
    $this->get(route('email-two-factor.login'))->assertRedirect(route('login'));
});

test('a code check that cannot get its turn is refused without using up the code', function () {
    Sleep::fake(syncWithCarbon: true);
    $user = User::factory()->withHrAccount()->create(['email_two_factor_enabled' => true]);

    $code = passwordStepEmailsCode($user);

    $otherCheck = Cache::lock('email-login-code-lock:'.$user->getKey(), 60);
    expect($otherCheck->get())->toBeTrue();

    expect(EmailLoginCodes::verify($user, $code))->toBeFalse();

    $otherCheck->release();

    expect(EmailLoginCodes::verify($user, $code))->toBeTrue();
});

test('resending the code is capped per hour, however slowly it is asked for', function () {
    $this->freezeTime();
    $user = User::factory()->withHrAccount()->create(['email_two_factor_enabled' => true]);
    passwordStepEmailsCode($user);

    foreach (range(1, 10) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => "10.0.6.{$attempt}"])->post(route('email-two-factor.resend'))
            ->assertStatus(302);
        $this->travel(61)->seconds();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.6.99'])
        ->post(route('email-two-factor.resend'))
        ->assertStatus(429);
});
