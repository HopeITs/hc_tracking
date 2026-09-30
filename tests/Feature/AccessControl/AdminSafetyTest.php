<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Policies\UserPolicy;
use Livewire\Livewire;

beforeEach(function () {
    $this->generalManager = userWithRole('General Manager');
    $this->superAdmin = userWithRole('Super Admin');
    $this->target = userWithRole('Team Member');
    $this->page = fn (User $actor) => Livewire::actingAs($actor)->test('pages::admin.users');
});

test('a general manager can give an ordinary role', function () {
    ($this->page)($this->generalManager)
        ->call('selectUser', $this->target->id)
        ->set('userRoleIds', [Role::findByName('MEAL', 'web')->id])
        ->call('saveUserAccess')
        ->assertHasNoErrors();

    expect($this->target->fresh()->hasRole('MEAL'))->toBeTrue();
});

test('only a super admin can give a role that carries user-management rights', function (string $role) {
    ($this->page)($this->generalManager)
        ->call('selectUser', $this->target->id)
        ->set('userRoleIds', [Role::findByName($role, 'web')->id])
        ->call('saveUserAccess')
        ->assertForbidden();

    expect($this->target->fresh()->hasRole($role))->toBeFalse();

    ($this->page)($this->superAdmin)
        ->call('selectUser', $this->target->id)
        ->set('userRoleIds', [Role::findByName($role, 'web')->id])
        ->call('saveUserAccess')
        ->assertHasNoErrors();

    expect($this->target->fresh()->hasRole($role))->toBeTrue();
})->with(['Super Admin', 'General Manager']);

test('only a super admin can grant access-management permissions directly', function () {
    ($this->page)($this->generalManager)
        ->call('selectUser', $this->target->id)
        ->set('userPermissionIds', [Permission::findByName('access-management.manage-users', 'web')->id])
        ->call('saveUserAccess')
        ->assertForbidden();

    expect($this->target->fresh()->hasDirectPermission('access-management.manage-users'))->toBeFalse();
});

test('a general manager cannot edit or delete a super admin', function () {
    $policy = new UserPolicy;

    expect($policy->update($this->generalManager, $this->superAdmin))->toBeFalse()
        ->and($policy->delete($this->generalManager, $this->superAdmin))->toBeFalse()
        ->and($policy->update($this->generalManager, $this->target))->toBeTrue();
});

test('a super admin can be removed by another super admin, but the last one cannot', function () {
    User::role('Super Admin')->whereKeyNot($this->superAdmin->id)->get()->each(fn (User $user) => $user->removeRole('Super Admin'));
    $secondAdmin = userWithRole('Super Admin');
    $policy = new UserPolicy;

    expect($policy->delete($secondAdmin, $this->superAdmin))->toBeTrue();

    $secondAdmin->removeRole('Super Admin');

    expect($policy->delete($secondAdmin->fresh(), $this->superAdmin))->toBeFalse();
});

test('creating a user with a privileged starting role needs a super admin', function () {
    $hrUserId = insertHrUser(insertHrEmployee(), 'starter@example.org');

    ($this->page)($this->generalManager)
        ->call('openCreateModal')
        ->set('hrUserId', (string) $hrUserId)
        ->set('initialRoleId', (string) Role::findByName('Super Admin', 'web')->id)
        ->call('createUser')
        ->assertForbidden();

    expect(User::query()->where('hr_user_id', $hrUserId)->exists())->toBeFalse();
});
