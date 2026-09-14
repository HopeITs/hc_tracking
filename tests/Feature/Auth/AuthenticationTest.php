<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Features;

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

test('the local tracking password no longer logs anyone in', function () {
    $user = User::factory()->withHrAccount('hr-secret')->create([
        'password' => Hash::make('local-secret'),
    ]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'local-secret',
    ])->assertSessionHasErrorsIn('email');

    $this->assertGuest();
});

test('an hr account logging in for the first time gets a tracking user as an officer', function () {
    $employeeId = insertHrEmployee(firstNameEn: 'Mai', lastNameEn: 'Nasser');
    insertHrUser($employeeId, 'new.person@example.org', 'password');

    $this->post(route('login.store'), [
        'email' => 'new.person@example.org',
        'password' => 'password',
    ])->assertSessionHasNoErrors();

    $user = User::query()->where('email', 'new.person@example.org')->sole();

    $this->assertAuthenticatedAs($user);
    expect($user->role)->toBe(UserRole::Officer)
        ->and($user->name)->toBe('Mai Nasser')
        ->and($user->employee_id)->toBe($employeeId);
});

test('listed hr admin emails start as admins on first login', function () {
    config(['auth.hr_admin_emails' => ['boss@example.org']]);
    insertHrUser(insertHrEmployee(), 'boss@example.org', 'password');

    $this->post(route('login.store'), [
        'email' => 'boss@example.org',
        'password' => 'password',
    ])->assertSessionHasNoErrors();

    expect(User::query()->sole()->role)->toBe(UserRole::Admin);
});

test('an existing role is kept when that user logs in again', function () {
    config(['auth.hr_admin_emails' => ['boss@example.org']]);
    $user = User::factory()->role(UserRole::Manager)->withHrAccount()->create(['email' => 'boss@example.org']);

    $this->post(route('login.store'), ['email' => 'boss@example.org', 'password' => 'password']);

    expect($user->refresh()->role)->toBe(UserRole::Manager);
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

test('a tracking user without an hr account is refused', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
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
