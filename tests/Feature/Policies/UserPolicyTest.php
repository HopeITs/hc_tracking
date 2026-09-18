<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Policies\UserPolicy;

function userForPolicy(int $id, ?string $roleName): User
{
    $user = new User;
    $user->setRawAttributes(['id' => $id]);
    $user->exists = true;

    if ($roleName !== null) {
        $user->assignRole(Role::findOrCreate($roleName, 'web'));
    }

    return $user;
}

beforeEach(function () {
    Permission::query()->create(['name' => 'access-management.manage-users', 'guard_name' => 'web']);
    Role::findOrCreate('Super Admin', 'web')->givePermissionTo('access-management.manage-users');
    Role::findOrCreate('Managers', 'web');
});

test('only a role with the manage-users permission can list view and create users', function (?string $role, bool $allowed) {
    $actor = userForPolicy(1, $role);
    $targetUser = userForPolicy(2, null);
    $policy = new UserPolicy;

    expect($policy->viewAny($actor))->toBe($allowed)
        ->and($policy->view($actor, $targetUser))->toBe($allowed)
        ->and($policy->create($actor))->toBe($allowed);
})->with([
    'no role' => [null, false],
    'managers (no manage-users permission)' => ['Managers', false],
    'super admin' => ['Super Admin', true],
]);

test('a manager-of-users can update and delete other users but not themselves', function () {
    $admin = userForPolicy(1, 'Super Admin');
    $otherUser = userForPolicy(2, null);
    $policy = new UserPolicy;

    expect($policy->update($admin, $otherUser))->toBeTrue()
        ->and($policy->delete($admin, $otherUser))->toBeTrue()
        ->and($policy->update($admin, $admin))->toBeFalse()
        ->and($policy->delete($admin, $admin))->toBeFalse()
        ->and($policy->restore($admin, $otherUser))->toBeFalse()
        ->and($policy->forceDelete($admin, $otherUser))->toBeFalse();
});

test('a user without the manage-users permission cannot update or delete users', function () {
    $actor = userForPolicy(1, 'Managers');
    $targetUser = userForPolicy(2, null);
    $policy = new UserPolicy;

    expect($policy->update($actor, $targetUser))->toBeFalse()
        ->and($policy->delete($actor, $targetUser))->toBeFalse();
});
