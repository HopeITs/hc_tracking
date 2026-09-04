<?php

use App\Models\User;

test('a user with no role given is stored as an Officer', function () {
    $user = User::factory()->create();

    $user->refresh();

    expect($user->role)->toBe('Officer');
    $this->assertDatabaseHas('users', ['id' => $user->id, 'role' => 'Officer']);
});

test('a role assigned to a user is stored verbatim', function (string $role) {
    $user = User::factory()->create(['role' => $role]);

    $user->refresh();

    expect($user->role)->toBe($role);
    $this->assertDatabaseHas('users', ['id' => $user->id, 'role' => $role]);
})->with([
    'manager' => 'manager',
    'meal' => 'meal',
]);
