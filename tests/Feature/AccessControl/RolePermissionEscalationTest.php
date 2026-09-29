<?php

use App\Models\Activity;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

/*
| Someone who may edit roles but is not a Super Admin must not be able to
| promote themselves, or anyone else, into user-management rights through
| the Roles tab.
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->clerkRole = Role::query()->create(['name' => 'Access Clerk', 'guard_name' => 'web']);
    $this->clerkRole->givePermissionTo([
        'access-management.manage-users',
        'access-management.manage-roles',
        'access-management.manage-permissions',
    ]);

    $this->clerk = Activity::withoutRecording(fn (): User => User::factory()->role('Access Clerk')->create());
    $this->superAdmin = userWithRole('Super Admin');
    $this->meal = Role::findByName('MEAL', 'web');
    $this->manageUsers = Permission::findByName('access-management.manage-users', 'web');

    $this->rolesTab = fn (User $actor, Role $role) => Livewire::actingAs($actor)
        ->test('pages::admin.users')
        ->call('showTab', 'roles')
        ->call('selectRole', $role->id);
});

/**
 * @return list<int>
 */
function permissionIdsOf(Role $role): array
{
    return $role->fresh()->permissions->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
}

test('a non-admin cannot change the permissions of a role they hold', function () {
    $before = permissionIdsOf($this->clerkRole);

    ($this->rolesTab)($this->clerk, $this->clerkRole)
        ->set('rolePermissionIds', Permission::query()->pluck('id')->all())
        ->call('saveRolePermissions')
        ->assertForbidden();

    expect(permissionIdsOf($this->clerkRole))->toEqualCanonicalizing($before);
});

test('a non-admin cannot give user-management permissions to an ordinary role', function () {
    ($this->rolesTab)($this->clerk, $this->meal)
        ->set('rolePermissionIds', [...permissionIdsOf($this->meal), $this->manageUsers->id])
        ->call('saveRolePermissions')
        ->assertForbidden();

    expect($this->meal->fresh()->hasPermissionTo('access-management.manage-users'))->toBeFalse();
});

test('a non-admin cannot change a role that carries user-management rights', function () {
    $auditors = Role::query()->create(['name' => 'Auditors', 'guard_name' => 'web']);
    $auditors->givePermissionTo(['access-management.manage-users', 'projects.view']);

    ($this->rolesTab)($this->clerk, $auditors)
        ->set('rolePermissionIds', [Permission::findByName('projects.view', 'web')->id])
        ->call('saveRolePermissions')
        ->assertForbidden();

    expect($auditors->fresh()->hasPermissionTo('access-management.manage-users'))->toBeTrue();
});

test('a non-admin can still change ordinary permissions of an ordinary role', function () {
    ($this->rolesTab)($this->clerk, $this->meal)
        ->set('rolePermissionIds', [...permissionIdsOf($this->meal), Permission::findByName('projects.create', 'web')->id])
        ->call('saveRolePermissions')
        ->assertHasNoErrors();

    expect($this->meal->fresh()->hasPermissionTo('projects.create'))->toBeTrue();
});

test('select all leaves user-management permissions alone for a non-admin', function () {
    $selected = ($this->rolesTab)($this->clerk, $this->meal)
        ->call('selectAllRolePermissions')
        ->get('rolePermissionIds');

    expect($selected)->not->toContain($this->manageUsers->id)
        ->and($selected)->toContain(Permission::findByName('projects.create', 'web')->id);
});

test('a non-admin cannot rename or delete their own role', function (string $action) {
    ($this->rolesTab)($this->clerk, $this->clerkRole)
        ->set('selectedRoleName', 'Renamed Clerk')
        ->call($action)
        ->assertForbidden();

    expect(Role::query()->where('name', 'Access Clerk')->exists())->toBeTrue();
})->with(['renameRole', 'deleteRole']);

test('a super admin can still give user-management permissions to a role', function () {
    ($this->rolesTab)($this->superAdmin, $this->meal)
        ->set('rolePermissionIds', [...permissionIdsOf($this->meal), $this->manageUsers->id])
        ->call('saveRolePermissions')
        ->assertHasNoErrors();

    expect($this->meal->fresh()->hasPermissionTo('access-management.manage-users'))->toBeTrue();
});

test('the roles tab tells a non-admin why their own role is locked', function () {
    ($this->rolesTab)($this->clerk, $this->clerkRole)
        ->assertSeeHtml('data-test="role-admin-only-note"');

    ($this->rolesTab)($this->clerk, $this->meal)
        ->assertDontSeeHtml('data-test="role-admin-only-note"');
});
