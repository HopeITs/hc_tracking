<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Policies\UserPolicy;

function userForPolicy(int $id, UserRole $role): User
{
    $user = new User;
    $user->setRawAttributes([
        'id' => $id,
        'role' => $role->value,
    ]);

    return $user;
}

test('only administrators can list view and create users', function (UserRole $role, bool $allowed) {
    $actor = userForPolicy(1, $role);
    $targetUser = userForPolicy(2, UserRole::Officer);
    $policy = new UserPolicy;

    expect($policy->viewAny($actor))->toBe($allowed)
        ->and($policy->view($actor, $targetUser))->toBe($allowed)
        ->and($policy->create($actor))->toBe($allowed);
})->with([
    'officer' => [UserRole::Officer, false],
    'manager' => [UserRole::Manager, false],
    'meal' => [UserRole::Meal, false],
    'admin' => [UserRole::Admin, true],
]);

test('administrators can update and delete other users but not themselves', function () {
    $admin = userForPolicy(1, UserRole::Admin);
    $otherUser = userForPolicy(2, UserRole::Officer);
    $policy = new UserPolicy;

    expect($policy->update($admin, $otherUser))->toBeTrue()
        ->and($policy->delete($admin, $otherUser))->toBeTrue()
        ->and($policy->update($admin, $admin))->toBeFalse()
        ->and($policy->delete($admin, $admin))->toBeFalse()
        ->and($policy->restore($admin, $otherUser))->toBeFalse()
        ->and($policy->forceDelete($admin, $otherUser))->toBeFalse();
});

test('staff cannot update or delete users', function (UserRole $role) {
    $actor = userForPolicy(1, $role);
    $targetUser = userForPolicy(2, UserRole::Officer);
    $policy = new UserPolicy;

    expect($policy->update($actor, $targetUser))->toBeFalse()
        ->and($policy->delete($actor, $targetUser))->toBeFalse();
})->with([
    'officer' => UserRole::Officer,
    'manager' => UserRole::Manager,
    'meal' => UserRole::Meal,
]);
