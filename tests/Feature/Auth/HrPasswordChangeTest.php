<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;

/**
 * Change a user's password in HR, as the HR system would.
 */
function changeHrPassword(User $user, string $password): void
{
    DB::connection('hr')->table('users')->where('id', $user->hr_user_id)->update([
        'password' => Hash::make($password),
    ]);
}

/**
 * Point the HR connection at a database that cannot be opened.
 */
function makeHrUnreachable(): void
{
    config(['database.connections.hr.database' => '/missing/dir/hr.sqlite']);
    DB::purge('hr');
}

test('changing the hr password signs an open session out', function () {
    $user = User::factory()->withHrAccount('old-secret')->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'old-secret',
    ])->assertSessionHasNoErrors();

    $this->get(route('dashboard'))->assertOk();

    // Once HR is asked again, an unchanged password keeps the session.
    $this->travel(61)->seconds();
    $this->get(route('dashboard'))->assertOk();

    changeHrPassword($user, 'new-secret');
    $this->travel(61)->seconds();

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});

test('a session signed in before fingerprints were checked is given one and stays signed in', function () {
    $user = User::factory()->withHrAccount('old-secret')->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'old-secret']);
    session()->forget('password_hash_web');

    $this->get(route('dashboard'))->assertOk();

    $hrPasswordHash = DB::connection('hr')->table('users')->where('id', $user->hr_user_id)->value('password');
    expect(session('password_hash_web'))->toBe(Auth::guard('web')->hashPasswordForCookie($hrPasswordHash));

    $this->travel(61)->seconds();
    $this->get(route('dashboard'))->assertOk();
    $this->assertAuthenticatedAs($user);

    // The fingerprint it was given holds it to that password from now on.
    changeHrPassword($user, 'new-secret');
    $this->travel(61)->seconds();

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});

test('logging in with a new hr password is not undone by the check cached before the change', function () {
    $user = User::factory()->withHrAccount('old-secret')->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'old-secret']);
    $this->get(route('dashboard'))->assertOk();
    $this->post(route('logout'));

    changeHrPassword($user, 'new-secret');

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'new-secret',
    ])->assertSessionHasNoErrors();

    $this->get(route('dashboard'))->assertOk();
    $this->assertAuthenticatedAs($user);
});

test('a remember cookie issued before an hr password change no longer signs in', function () {
    $user = User::factory()->withHrAccount('old-secret')->create();
    $recallerName = Auth::guard('web')->getRecallerName();

    $rememberCookie = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'old-secret',
        'remember' => 'on',
    ])->getCookie($recallerName)->getValue();

    $visitWithOnlyTheRememberCookie = function () use ($recallerName, $rememberCookie) {
        Auth::forgetGuards();
        session()->flush();

        return $this->withCookie($recallerName, $rememberCookie)->get(route('dashboard'));
    };

    $visitWithOnlyTheRememberCookie()->assertOk();
    $this->assertAuthenticatedAs($user);

    changeHrPassword($user, 'new-secret');

    $visitWithOnlyTheRememberCookie()->assertRedirect(route('login'));
    $this->assertGuest();
});

test('while hr is unreachable a remember cookie signs nobody in and the login page still loads', function () {
    Exceptions::fake();
    $user = User::factory()->withHrAccount('hr-secret')->create();
    $recallerName = Auth::guard('web')->getRecallerName();

    $rememberCookie = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'hr-secret',
        'remember' => 'on',
    ])->getCookie($recallerName)->getValue();

    // A cookie from before sessions followed the HR password carried the
    // fingerprint of an empty password.
    $legacyCookie = $user->id.'|'.$user->fresh()->remember_token.'|'.Auth::guard('web')->hashPasswordForCookie('');

    makeHrUnreachable();

    foreach ([$rememberCookie, $legacyCookie] as $cookie) {
        Auth::forgetGuards();
        session()->flush();

        $this->withCookie($recallerName, $cookie)->get(route('login'))->assertOk();
        $this->assertGuest();
    }

    Exceptions::assertReported(QueryException::class);
});

test('a user hr had taken access from is still signed out once hr becomes unreachable', function () {
    $employeeId = insertHrEmployee();
    insertHrUser($employeeId, 'rana@example.org');
    $user = User::factory()->create(['email' => 'rana@example.org']);

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    DB::connection('hr')->table('employees')->where('id', $employeeId)->update(['is_active' => false]);
    $this->travel(61)->seconds();
    $this->get(route('dashboard'))->assertRedirect(route('login'));

    // Another session of the same user, while HR cannot be asked again.
    makeHrUnreachable();

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});
