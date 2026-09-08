<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use ValueError;

test('a user with no role given is stored as an Officer', function () {
    $user = User::factory()->create();

    $user->refresh();

    expect($user->role)->toBe(UserRole::Officer);
    $this->assertDatabaseHas('users', ['id' => $user->id, 'role' => 'Officer']);
});

test('a role assigned to a user is stored as its enum value', function (UserRole $role, string $stored) {
    $user = User::factory()->create(['role' => $role]);

    $user->refresh();

    expect($user->role)->toBe($role);
    $this->assertDatabaseHas('users', ['id' => $user->id, 'role' => $stored]);
})->with([
    'officer' => [UserRole::Officer, 'Officer'],
    'manager' => [UserRole::Manager, 'Manager'],
    'meal' => [UserRole::Meal, 'MEAL'],
    'admin' => [UserRole::Admin, 'Admin'],
]);

/**
 * Casing is part of the value. Before the enum the column was free text, so
 * 'meal' and 'MEAL' were two different roles.
 */
test('a role that differs only by casing is not a valid role', function () {
    DB::table('users')->where('id', User::factory()->create()->id)->update(['role' => 'meal']);

    User::query()->sole()->role;
})->throws(ValueError::class);

test('every role value fits the fifty character column', function () {
    foreach (UserRole::values() as $value) {
        expect(strlen($value))->toBeLessThanOrEqual(50);
    }
});

test('the column default matches the enum', function () {
    expect(UserRole::tryFrom('Officer'))->toBe(UserRole::Officer);
});
