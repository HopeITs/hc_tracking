<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    Role::findOrCreate('Super Admin', 'web')->givePermissionTo([
        Permission::query()->firstOrCreate(['name' => 'access-management.manage-users', 'guard_name' => 'web']),
        Permission::query()->firstOrCreate(['name' => 'access-management.manage-roles', 'guard_name' => 'web']),
        Permission::query()->firstOrCreate(['name' => 'access-management.manage-permissions', 'guard_name' => 'web']),
        Permission::query()->firstOrCreate(['name' => 'programs.view', 'guard_name' => 'web']),
    ]);
    Role::findOrCreate('Officer', 'web');
    Role::findOrCreate('Managers', 'web');
    Role::findOrCreate('MEAL', 'web');
});

function accessAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole('Super Admin');
    $user->setActiveRole(Role::query()->where('name', 'Super Admin')->sole()->id);

    return $user;
}

test('guests are redirected from the user directory', function () {
    $this->get(route('admin.users.index'))->assertRedirect(route('login'));
});

test('staff roles are forbidden from the user directory', function (string $roleName) {
    $user = User::factory()->role($roleName)->create();

    $this->actingAs($user)
        ->get(route('admin.users.index'))
        ->assertForbidden();
})->with([
    'officer' => 'Officer',
    'manager' => 'Managers',
    'meal' => 'MEAL',
]);

test('administrators see the user directory in the application navigation', function () {
    $admin = accessAdmin();

    $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertOk()
        ->assertSeeText('User Directory')
        ->assertSee(route('admin.users.index'), false);
});

test('staff do not see the user directory in the application navigation', function () {
    $officer = User::factory()->role('Officer')->create();

    $this->actingAs($officer)
        ->get(route('dashboard'))
        ->assertDontSee(route('admin.users.index'), false);
});

test('the page opens on the users tab and can switch to the roles tab', function () {
    $admin = accessAdmin();

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->assertSet('activeTab', 'users')
        ->call('showTab', 'roles')
        ->assertSet('activeTab', 'roles');
});

test('administrators can view and filter the user directory', function () {
    $admin = accessAdmin();
    User::factory()->role('Officer')->create(['name' => 'Visible Officer']);
    User::factory()->role('Managers')->create(['name' => 'Hidden Manager']);

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->assertSee('Visible Officer')
        ->assertSee('Hidden Manager')
        ->set('search', 'Visible')
        ->assertSee('Visible Officer')
        ->assertDontSee('Hidden Manager')
        ->set('search', '')
        ->set('role', 'Managers')
        ->assertSee('Hidden Manager')
        ->assertDontSee('Visible Officer');
});

test('administrators can reset directory filters', function () {
    $admin = accessAdmin();

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->set('search', 'Manager')
        ->set('role', 'Managers')
        ->call('clearFilters')
        ->assertSet('search', '')
        ->assertSet('role', '');
});

test('user names are escaped in the directory', function () {
    $admin = accessAdmin();
    $unsafeName = '<script>alert("directory")</script>';
    User::factory()->create(['name' => $unsafeName]);

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->assertSee($unsafeName)
        ->assertDontSee($unsafeName, false);
});

test('administrators can give an hr account access with an initial role', function () {
    $admin = accessAdmin();
    $employeeId = insertHrEmployee(firstNameEn: 'Amina', lastNameEn: 'Haddad');
    $hrUserId = insertHrUser($employeeId, 'amina@example.org');
    $meal = Role::query()->where('name', 'MEAL')->sole();

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('openCreateModal')
        ->assertSee('amina@example.org')
        ->set('hrUserId', (string) $hrUserId)
        ->set('initialRoleId', (string) $meal->id)
        ->call('createUser')
        ->assertHasNoErrors();

    $user = User::query()->where('hr_user_id', $hrUserId)->sole();

    expect($user->name)->toBe('Amina Haddad')
        ->and($user->roles->pluck('name')->all())->toBe(['MEAL'])
        ->and($user->active_role_id)->toBe($meal->id)
        ->and($user->hr_user_id)->toBe($hrUserId)
        ->and($user->employee_id)->toBe($employeeId)
        ->and($user->email_verified_at)->not->toBeNull();
});

test('an hr account given access with no role chosen starts as Team Member', function () {
    $admin = accessAdmin();
    $hrUserId = insertHrUser(insertHrEmployee(), 'dana@example.org');

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('openCreateModal')
        ->set('hrUserId', (string) $hrUserId)
        ->call('createUser')
        ->assertHasNoErrors();

    $user = User::query()->where('hr_user_id', $hrUserId)->sole();

    expect($user->roles->pluck('name')->all())->toBe(['Team Member'])
        ->and($user->activeRole?->name)->toBe('Team Member');
});

test('hr accounts that already have access are not offered again', function () {
    $admin = accessAdmin();
    User::factory()->create(['email' => 'taken@example.org']);
    insertHrUser(insertHrEmployee(), 'free@example.org');

    $component = Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('openCreateModal');

    expect($component->instance()->hrAccountOptions->pluck('email')->all())->toBe(['free@example.org']);
});

test('a new user must name an available hr account', function () {
    $admin = accessAdmin();

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('openCreateModal')
        ->set('hrUserId', '999999')
        ->call('createUser')
        ->assertHasErrors(['hrUserId' => 'exists']);

    expect(User::query()->count())->toBe(1);
});

test('an hr account that already has access cannot be added twice', function () {
    $admin = accessAdmin();
    $hrUserId = User::factory()->create(['email' => 'taken@example.org'])->hr_user_id;

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('openCreateModal')
        ->set('hrUserId', (string) $hrUserId)
        ->call('createUser')
        ->assertHasErrors(['hrUserId']);

    expect(User::query()->where('hr_user_id', $hrUserId)->count())->toBe(1);
});

test('selecting a user loads their assigned roles, active role, and direct permissions', function () {
    $admin = accessAdmin();
    $managers = Role::query()->where('name', 'Managers')->sole();
    $meal = Role::query()->where('name', 'MEAL')->sole();
    $permission = Permission::query()->where('name', 'programs.view')->sole();

    $user = User::factory()->create();
    $user->assignRole([$managers, $meal]);
    $user->setActiveRole($meal->id);
    $user->givePermissionTo($permission);

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('selectUser', $user->id)
        ->assertSet('userRoleIds', fn (array $ids): bool => collect($ids)->sort()->values()->all() === collect([$managers->id, $meal->id])->sort()->values()->all())
        ->assertSet('userActiveRoleId', $meal->id)
        ->assertSet('userPermissionIds', [$permission->id]);
});

test('administrators can assign multiple roles, pick an active role, and grant a direct permission', function () {
    $admin = accessAdmin();
    $user = User::factory()->role('Officer')->create(['name' => 'Rana Darwish', 'email' => 'rana@example.org']);
    $officer = Role::query()->where('name', 'Officer')->sole();
    $managers = Role::query()->where('name', 'Managers')->sole();
    $permission = Permission::query()->where('name', 'programs.view')->sole();

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('selectUser', $user->id)
        ->set('userRoleIds', [$officer->id, $managers->id])
        ->set('userActiveRoleId', $managers->id)
        ->set('userPermissionIds', [$permission->id])
        ->call('saveUserAccess')
        ->assertHasNoErrors();

    $refreshed = $user->refresh();

    expect($refreshed->roles->pluck('id')->all())->toEqualCanonicalizing([$officer->id, $managers->id])
        ->and($refreshed->active_role_id)->toBe($managers->id)
        ->and($refreshed->permissions->pluck('id')->all())->toBe([$permission->id])
        ->and($refreshed->name)->toBe('Rana Darwish')
        ->and($refreshed->email)->toBe('rana@example.org');
});

test('unassigning the active role clears it automatically', function () {
    $admin = accessAdmin();
    $officer = Role::query()->where('name', 'Officer')->sole();
    $managers = Role::query()->where('name', 'Managers')->sole();
    $user = User::factory()->create();
    $user->assignRole([$officer, $managers]);
    $user->setActiveRole($managers->id);

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('selectUser', $user->id)
        ->set('userRoleIds', [$officer->id])
        ->call('saveUserAccess')
        ->assertHasNoErrors();

    expect($user->refresh())
        ->roles->pluck('id')->all()->toBe([$officer->id])
        ->and($user->active_role_id)->toBe($officer->id);
});

test('administrators cannot edit or delete themselves through the directory', function () {
    $admin = accessAdmin();

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('selectUser', $admin->id)
        ->set('userRoleIds', [])
        ->call('saveUserAccess')
        ->assertForbidden();

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('confirmUserDeletion', $admin->id)
        ->assertForbidden();

    $this->assertModelExists($admin);
});

test('administrators can delete an unreferenced user', function () {
    $admin = accessAdmin();
    $user = User::factory()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('confirmUserDeletion', $user->id)
        ->call('deleteUser')
        ->assertSet('showDeleteModal', false);

    $this->assertModelMissing($user);
});

test('referenced users remain when the database prevents deletion', function () {
    $admin = accessAdmin();
    $user = User::factory()->create();
    Task::factory()->create(['created_by' => $user->id]);

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('confirmUserDeletion', $user->id)
        ->call('deleteUser')
        ->assertSet('showDeleteModal', true)
        ->assertSee('This user is linked to operational records and cannot be deleted.');

    $this->assertModelExists($user);
});

// --- Roles tab ---

test('an authorized user sees every seeded role on the roles tab', function () {
    $admin = accessAdmin();

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('showTab', 'roles')
        ->assertSeeText('Super Admin')
        ->assertSeeText('Managers');
});

test('an authorized user can create a role from the roles tab', function () {
    $admin = accessAdmin();

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('showTab', 'roles')
        ->set('newRoleName', 'Regional Auditor')
        ->call('createRole')
        ->assertHasNoErrors();

    expect(Role::query()->where('name', 'Regional Auditor')->exists())->toBeTrue();
});

test('a duplicate role name is rejected', function () {
    $admin = accessAdmin();

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('showTab', 'roles')
        ->set('newRoleName', 'Managers')
        ->call('createRole')
        ->assertHasErrors(['newRoleName']);
});

test('an authorized user can rename a custom role', function () {
    $admin = accessAdmin();
    $role = Role::create(['name' => 'Field Staff', 'guard_name' => 'web']);

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('showTab', 'roles')
        ->call('selectRole', $role->id)
        ->set('selectedRoleName', 'Field Team')
        ->call('renameRole')
        ->assertHasNoErrors();

    expect($role->refresh()->name)->toBe('Field Team');
});

test('a built-in role keeps its name and cannot be deleted', function (string $action) {
    $admin = accessAdmin();
    $role = Role::query()->where('name', 'Managers')->sole();

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('showTab', 'roles')
        ->call('selectRole', $role->id)
        ->assertSeeHtml('data-test="role-built-in-note"')
        ->assertDontSeeHtml('id="selected-role-name"')
        ->set('selectedRoleName', 'Programme Managers')
        ->call($action)
        ->assertForbidden();

    expect($role->refresh()->name)->toBe('Managers');
})->with(['renameRole', 'deleteRole']);

test('super admin cannot be renamed or deleted', function () {
    $admin = accessAdmin();
    $superAdmin = Role::query()->where('name', 'Super Admin')->sole();

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('showTab', 'roles')
        ->call('selectRole', $superAdmin->id)
        ->set('selectedRoleName', 'Renamed')
        ->call('renameRole')
        ->assertForbidden();

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('showTab', 'roles')
        ->call('selectRole', $superAdmin->id)
        ->call('deleteRole')
        ->assertForbidden();

    expect(Role::query()->where('name', 'Super Admin')->exists())->toBeTrue();
});

test('an authorized user can delete a custom role with no users', function () {
    $admin = accessAdmin();
    $role = Role::create(['name' => 'Field Staff', 'guard_name' => 'web']);

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('showTab', 'roles')
        ->call('selectRole', $role->id)
        ->call('deleteRole')
        ->assertHasNoErrors();

    expect(Role::query()->where('name', 'Field Staff')->exists())->toBeFalse();
});

test('an authorized user can toggle a permission on a role', function () {
    $admin = accessAdmin();
    $role = Role::query()->where('name', 'MEAL')->sole();
    $permission = Permission::query()->where('name', 'programs.view')->sole();

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('showTab', 'roles')
        ->call('selectRole', $role->id)
        ->set('rolePermissionIds', [$permission->id])
        ->call('saveRolePermissions')
        ->assertHasNoErrors();

    expect($role->fresh()->hasPermissionTo('programs.view'))->toBeTrue();
});

test('the assigned users modal lists holders of the selected role', function () {
    $admin = accessAdmin();
    $role = Role::query()->where('name', 'Managers')->sole();
    $holder = User::factory()->create(['name' => 'Role Holder']);
    $holder->assignRole($role);

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('showTab', 'roles')
        ->call('selectRole', $role->id)
        ->call('showAssignedUsers')
        ->assertSet('showAssignedUsersModal', true)
        ->assertSee('Role Holder');
});

test('administrators can filter the directory by hr account status', function () {
    $admin = accessAdmin();
    User::factory()->create(['name' => 'Active Person']);
    $inactive = User::factory()->create(['name' => 'Departed Person']);
    DB::connection('hr')->table('employees')->where('id', $inactive->employee_id)->update(['is_active' => false]);

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->set('status', 'active')
        ->assertSee('Active Person')
        ->assertDontSee('Departed Person')
        ->set('status', 'inactive')
        ->assertSee('Departed Person')
        ->assertDontSee('Active Person');
});

test('hr accounts of inactive employees are neither offered nor accepted', function () {
    $admin = accessAdmin();
    insertHrUser(insertHrEmployee(), 'free@example.org');
    $goneId = insertHrUser(insertHrEmployee(isActive: false), 'gone@example.org');

    $component = Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('openCreateModal');

    expect($component->instance()->hrAccountOptions->pluck('email')->all())->toBe(['free@example.org']);

    $component
        ->set('hrUserId', (string) $goneId)
        ->call('createUser')
        ->assertHasErrors(['hrUserId']);

    expect(User::query()->where('hr_user_id', $goneId)->exists())->toBeFalse();
});

test('a user the database refuses to delete keeps their roles', function () {
    $admin = accessAdmin();
    $user = User::factory()->role('Managers')->create();
    Task::factory()->create(['created_by' => $user->id]);

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('confirmUserDeletion', $user->id)
        ->call('deleteUser');

    expect($user->refresh()->roles->pluck('name')->all())->toBe(['Managers']);
});
