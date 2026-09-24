<?php

use App\Models\Role;
use App\Models\User;

test('the command gives an hr account access with the given role', function () {
    Role::findOrCreate('MEAL', 'web');
    $employeeId = insertHrEmployee(firstNameEn: 'Amina', lastNameEn: 'Haddad');
    $hrUserId = insertHrUser($employeeId, 'amina@example.org');

    $this->artisan('user:create', [
        '--email' => 'amina@example.org',
        '--role' => 'MEAL',
        '--no-interaction' => true,
    ])->assertSuccessful();

    $user = User::query()->where('hr_user_id', $hrUserId)->sole();

    expect($user->name)->toBe('Amina Haddad')
        ->and($user->roles->first()?->name)->toBe('MEAL')
        ->and($user->hr_user_id)->toBe($hrUserId)
        ->and($user->employee_id)->toBe($employeeId)
        ->and($user->email_verified_at)->not->toBeNull();
});

test('a user has no role when none is given', function () {
    insertHrUser(insertHrEmployee(), 'dana@example.org');

    $this->artisan('user:create', [
        '--email' => 'dana@example.org',
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect(User::query()->sole()->roles)->toBeEmpty();
});

test('the unverified flag leaves the address unverified', function () {
    insertHrUser(insertHrEmployee(), 'dana@example.org');

    $this->artisan('user:create', [
        '--email' => 'dana@example.org',
        '--unverified' => true,
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect(User::query()->sole()->email_verified_at)->toBeNull();
});

test('an email with no hr account is rejected', function () {
    $this->artisan('user:create', [
        '--email' => 'nobody@example.org',
        '--no-interaction' => true,
    ])->assertFailed();

    $this->assertDatabaseCount('users', 0);
});

test('an unknown role is rejected', function () {
    insertHrUser(insertHrEmployee(), 'dana@example.org');

    $this->artisan('user:create', [
        '--email' => 'dana@example.org',
        '--role' => 'not-a-real-role',
        '--no-interaction' => true,
    ])->assertFailed();

    $this->assertDatabaseCount('users', 0);
});

test('a duplicate email is rejected', function () {
    User::factory()->create(['email' => 'taken@example.org']);

    $this->artisan('user:create', [
        '--email' => 'taken@example.org',
        '--no-interaction' => true,
    ])->assertFailed();

    $this->assertDatabaseCount('users', 1);
});

test('a missing email is rejected', function () {
    $this->artisan('user:create', ['--no-interaction' => true])->assertFailed();

    $this->assertDatabaseCount('users', 0);
});
