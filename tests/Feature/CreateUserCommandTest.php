<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('the command creates a verified user with the given role', function () {
    $this->artisan('user:create', [
        '--name' => 'Amina Haddad',
        '--email' => 'amina@example.org',
        '--role' => 'MEAL',
        '--password' => 'correct-horse-battery-staple',
        '--no-interaction' => true,
    ])->assertSuccessful();

    $user = User::query()->where('email', 'amina@example.org')->sole();

    expect($user->name)->toBe('Amina Haddad')
        ->and($user->role)->toBe(UserRole::Meal)
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->password)->not->toBe('correct-horse-battery-staple');
});

test('the password is hashed rather than stored in the clear', function () {
    $this->artisan('user:create', [
        '--name' => 'Sam Okoro',
        '--email' => 'sam@example.org',
        '--password' => 'correct-horse-battery-staple',
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect(Hash::check('correct-horse-battery-staple', User::query()->sole()->password))->toBeTrue();
});

test('a user defaults to Officer when no role is given', function () {
    $this->artisan('user:create', [
        '--name' => 'Dana Reyes',
        '--email' => 'dana@example.org',
        '--password' => 'correct-horse-battery-staple',
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect(User::query()->sole()->role)->toBe(UserRole::Officer);
});

test('the unverified flag leaves the address unverified', function () {
    $this->artisan('user:create', [
        '--name' => 'Dana Reyes',
        '--email' => 'dana@example.org',
        '--password' => 'correct-horse-battery-staple',
        '--unverified' => true,
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect(User::query()->sole()->email_verified_at)->toBeNull();
});

test('an unknown role is rejected', function () {
    $this->artisan('user:create', [
        '--name' => 'Dana Reyes',
        '--email' => 'dana@example.org',
        '--role' => 'meal',
        '--password' => 'correct-horse-battery-staple',
        '--no-interaction' => true,
    ])->assertFailed();

    $this->assertDatabaseCount('users', 0);
});

test('a duplicate email is rejected', function () {
    User::factory()->create(['email' => 'taken@example.org']);

    $this->artisan('user:create', [
        '--name' => 'Dana Reyes',
        '--email' => 'taken@example.org',
        '--password' => 'correct-horse-battery-staple',
        '--no-interaction' => true,
    ])->assertFailed();

    $this->assertDatabaseCount('users', 1);
});

test('a missing password is rejected rather than creating a passwordless user', function () {
    $this->artisan('user:create', [
        '--name' => 'Dana Reyes',
        '--email' => 'dana@example.org',
        '--no-interaction' => true,
    ])->assertFailed();

    $this->assertDatabaseCount('users', 0);
});
