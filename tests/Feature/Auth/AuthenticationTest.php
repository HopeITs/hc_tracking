<?php

use App\Http\Middleware\EnsureHrAccountIsActive;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Fortify\Features;
use Symfony\Component\HttpFoundation\Response;

test('login screen can be rendered', function () {
    $response = $this->get(route('login'));

    $response->assertOk();
});

test('users can authenticate with their hr password', function () {
    $user = User::factory()->withHrAccount()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

test('login always lands on the dashboard, never on the page visited before logging out', function () {
    $user = User::factory()->withHrAccount()->create();

    // Hitting a protected route while a guest stashes it as the "intended" URL.
    $this->get(route('tasks.index'));

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('dashboard', absolute: false));
});

test('inactive hr employees cannot log in', function () {
    $hrUserId = insertHrUser(insertHrEmployee(isActive: false), 'gone@example.org');

    $this->post(route('login.store'), [
        'email' => 'gone@example.org',
        'password' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(User::query()->where('hr_user_id', $hrUserId)->exists())->toBeFalse();
});

test('logging in links the user to their hr account and refreshes their name', function () {
    $employeeId = insertHrEmployee(firstNameEn: 'Rana', lastNameEn: 'Darwish');
    $hrUserId = insertHrUser($employeeId, 'rana@example.org', 'hr-secret');
    $user = User::factory()->create(['name' => 'Old Name', 'email' => 'rana@example.org']);

    $this->post(route('login.store'), [
        'email' => 'rana@example.org',
        'password' => 'hr-secret',
    ])->assertSessionHasNoErrors();

    expect($user->refresh())
        ->hr_user_id->toBe($hrUserId)
        ->employee_id->toBe($employeeId)
        ->name->toBe('Rana Darwish')
        ->and($user->employee->name_ar)->toBe('سامي حداد');
});

test('remember me issues a remember cookie that lasts 30 days', function () {
    $user = User::factory()->withHrAccount()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
        'remember' => 'on',
    ]);

    $response->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($user);

    // The recaller cookie is what actually keeps the session alive past
    // browser close; remember_token itself isn't a reliable signal here
    // since the factory always seeds one regardless of login.
    $response->assertCookie(Auth::guard()->getRecallerName());

    $recallerCookie = $response->getCookie(Auth::guard()->getRecallerName(), decrypt: false);
    expect($recallerCookie->getExpiresTime())
        ->toBeGreaterThan(now()->addDays(30)->subMinute()->timestamp)
        ->toBeLessThanOrEqual(now()->addDays(30)->addMinute()->timestamp);
});

test('without remember me no remember cookie is issued', function () {
    $user = User::factory()->withHrAccount()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($user);

    $response->assertCookieMissing(Auth::guard()->getRecallerName());
});

test('the session cookie itself expires when the browser closes, remember me or not', function () {
    // A cookie with expires-time 0 has no Max-Age/Expires attribute at all,
    // which is what makes a browser drop it on close. This must hold
    // whether or not "remember me" was checked — the two are independent:
    // remember me is a *separate*, long-lived recaller cookie that lets the
    // user skip the login page next time even though this session cookie
    // is gone; without it, closing the browser must require signing in
    // again rather than silently staying logged in for SESSION_LIFETIME.
    $user = User::factory()->withHrAccount()->create();

    $withoutRemember = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $sessionCookie = $withoutRemember->getCookie(config('session.cookie'), decrypt: false);
    expect($sessionCookie)->not->toBeNull();
    expect($sessionCookie->getExpiresTime())->toBe(0);

    $this->post(route('logout'));

    $withRemember = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
        'remember' => 'on',
    ]);

    $sessionCookieRemembered = $withRemember->getCookie(config('session.cookie'), decrypt: false);
    expect($sessionCookieRemembered)->not->toBeNull();
    expect($sessionCookieRemembered->getExpiresTime())->toBe(0);

    $recallerCookie = $withRemember->getCookie(Auth::guard()->getRecallerName(), decrypt: false);
    expect($recallerCookie->getExpiresTime())->toBeGreaterThan(now()->addDays(29)->timestamp);
});

test('logging in never writes to hr', function () {
    $user = User::factory()->withHrAccount()->create();
    $before = DB::connection('hr')->table('users')->get()->toArray();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    expect(DB::connection('hr')->table('users')->get()->toArray())->toEqual($before);
});

test('users can not authenticate with a wrong hr password', function () {
    $user = User::factory()->withHrAccount()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertSessionHasErrorsIn('email');

    $this->assertGuest();
});

test('a wrong password for a known account shows the error under the password field', function () {
    $user = User::factory()->withHrAccount()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $html = $this->get(route('login'))->getContent();

    expect($html)->toContain(__('auth.failed'));

    // The email is fine; only the password field carries the error.
    $passwordFieldStart = (int) strpos($html, 'id="login-password"');
    $rememberStart = (int) strpos($html, 'name="remember"');
    $passwordFieldMarkup = substr($html, $passwordFieldStart, $rememberStart - $passwordFieldStart);
    $emailFieldStart = (int) strpos($html, 'id="login-email"');
    $emailFieldMarkup = substr($html, $emailFieldStart, $passwordFieldStart - $emailFieldStart);

    expect($passwordFieldMarkup)->toContain(__('auth.failed'));
    expect($emailFieldMarkup)->not->toContain(__('auth.failed'));
});

test('an unknown email shows the error under the email field', function () {
    $this->post(route('login.store'), [
        'email' => 'nobody@example.org',
        'password' => 'whatever',
    ]);

    $html = $this->get(route('login'))->getContent();

    expect($html)->toContain(__('auth.failed'));

    $emailFieldStart = (int) strpos($html, 'id="login-email"');
    $passwordFieldStart = (int) strpos($html, 'id="login-password"');
    $emailFieldMarkup = substr($html, $emailFieldStart, $passwordFieldStart - $emailFieldStart);

    expect($emailFieldMarkup)->toContain(__('auth.failed'));
});

test('tracking keeps no copy of hr names, emails or passwords', function () {
    expect(Schema::hasColumns('users', ['name']))->toBeFalse()
        ->and(Schema::hasColumns('users', ['email']))->toBeFalse()
        ->and(Schema::hasColumns('users', ['password']))->toBeFalse();
});

test('a user shows the name and email hr holds', function () {
    $user = Activity::withoutRecording(fn (): User => User::factory()->create(['name' => 'Rana Darwish', 'email' => 'rana@example.org']));
    DB::connection('hr')->table('users')->where('id', $user->hr_user_id)->update(['email' => 'rana.d@example.org']);

    expect($user->fresh())
        ->name->toBe('Rana Darwish')
        ->email->toBe('rana.d@example.org');
});

test('an hr account logging in for the first time gets a Team Member tracking user', function () {
    $employeeId = insertHrEmployee(firstNameEn: 'Mai', lastNameEn: 'Nasser');
    $hrUserId = insertHrUser($employeeId, 'new.person@example.org', 'password');

    $this->post(route('login.store'), [
        'email' => 'new.person@example.org',
        'password' => 'password',
    ])->assertSessionHasNoErrors();

    $user = User::query()->where('hr_user_id', $hrUserId)->sole();

    $this->assertAuthenticatedAs($user);
    expect($user->roles->pluck('name')->all())->toBe(['Team Member'])
        ->and($user->name)->toBe('Mai Nasser')
        ->and($user->employee_id)->toBe($employeeId);
});

test('listed hr admin emails start as super admins on first login', function () {
    config(['auth.hr_admin_emails' => ['boss@example.org']]);
    insertHrUser(insertHrEmployee(), 'boss@example.org', 'password');

    $this->post(route('login.store'), [
        'email' => 'boss@example.org',
        'password' => 'password',
    ])->assertSessionHasNoErrors();

    expect(User::query()->sole()->roles->first()?->name)->toBe('Super Admin');
});

test('an existing role is kept when that user logs in again', function () {
    config(['auth.hr_admin_emails' => ['boss@example.org']]);
    $user = User::factory()->role('Managers')->withHrAccount()->create(['email' => 'boss@example.org']);

    $this->post(route('login.store'), ['email' => 'boss@example.org', 'password' => 'password']);

    expect($user->refresh()->roles->first()?->name)->toBe('Managers');
});

test('a wrong hr password creates no tracking user', function () {
    insertHrUser(insertHrEmployee(), 'new.person@example.org', 'password');

    $this->post(route('login.store'), [
        'email' => 'new.person@example.org',
        'password' => 'wrong-password',
    ])->assertSessionHasErrorsIn('email');

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
});

test('a tracking user whose hr account was removed is refused', function () {
    $user = User::factory()->create(['email' => 'orphan@example.org']);
    DB::connection('hr')->table('users')->where('id', $user->hr_user_id)->delete();

    $this->post(route('login.store'), [
        'email' => 'orphan@example.org',
        'password' => 'password',
    ])->assertSessionHasErrorsIn('email');

    $this->assertGuest();
});

test('users with two factor enabled are redirected to two factor challenge', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->withTwoFactor()->withHrAccount()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('two-factor.login'));
    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $response->assertRedirect(route('home'));

    $this->assertGuest();
});

test('password reset is not offered because passwords live in hr', function () {
    expect(Features::enabled(Features::resetPasswords()))->toBeFalse();

    $this->get(route('login'))->assertDontSee('Forgot your password?');
});

test('an email changed in hr still logs in as the same user', function () {
    $user = Activity::withoutRecording(fn (): User => User::factory()->withHrAccount('hr-secret')->create(['email' => 'old@example.org']));
    DB::connection('hr')->table('users')->where('id', $user->hr_user_id)->update(['email' => 'new@example.org']);

    $this->post(route('login.store'), [
        'email' => 'new@example.org',
        'password' => 'hr-secret',
    ])->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($user);
    expect(User::query()->count())->toBe(1)
        ->and($user->refresh()->email)->toBe('new@example.org');
});

test('a signed-in user is signed out once hr deactivates them', function () {
    $employeeId = insertHrEmployee();
    $hrUserId = insertHrUser($employeeId, 'rana@example.org');
    $user = User::factory()->create(['email' => 'rana@example.org']);

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    DB::connection('hr')->table('employees')->where('id', $employeeId)->update(['is_active' => false]);
    $this->travel(61)->seconds();

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});

test('a signed-in user whose hr account was removed is signed out', function () {
    $user = User::factory()->create();
    DB::connection('hr')->table('users')->where('id', $user->hr_user_id)->delete();

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});

test('an unreachable hr database does not sign anyone out', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    config(['database.connections.hr.database' => '/missing/dir/hr.sqlite']);
    DB::purge('hr');

    $request = Request::create('/');
    $request->setUserResolver(fn (): User => $user);
    $request->setLaravelSession(app('session.store'));

    $response = (new EnsureHrAccountIsActive)->handle($request, fn (): Response => new Response('ok'));

    expect($response->getContent())->toBe('ok');
    $this->assertAuthenticatedAs($user);
});
