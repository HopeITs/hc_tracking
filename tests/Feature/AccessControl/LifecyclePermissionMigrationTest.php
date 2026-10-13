<?php

use App\Models\Role;
use App\Models\User;

/**
 * Puts the database in the state it was in before T02: General Manager and
 * Managers enter targets, and Public Relations Manager has no funding
 * agreement permissions.
 */
beforeEach(function () {
    Role::findByName('General Manager', 'web')->givePermissionTo(['targets.create', 'targets.update']);
    Role::findByName('Managers', 'web')->givePermissionTo(['targets.create', 'targets.update']);
    Role::findByName('Public Relations Manager', 'web')->revokePermissionTo(['funding-agreements.view', 'funding-agreements.create', 'funding-agreements.update']);

    $this->lifecycleMigration = require glob(database_path('migrations/*_apply_lifecycle_permission_grant_changes.php'))[0];
});

test('the migration leaves target entry to MEAL alone', function () {
    $this->lifecycleMigration->up();

    $holders = Role::query()->whereHas('permissions', fn ($query) => $query->whereIn('name', ['targets.create', 'targets.update']))->pluck('name')->all();

    expect($holders)->toEqualCanonicalizing(['Super Admin', 'MEAL'])
        ->and(Role::findByName('General Manager', 'web')->hasPermissionTo('targets.delete'))->toBeTrue();
});

test('the migration gives Public Relations Manager funding agreements except delete', function () {
    $this->lifecycleMigration->up();

    $role = Role::findByName('Public Relations Manager', 'web');

    expect($role->hasPermissionTo('funding-agreements.view'))->toBeTrue()
        ->and($role->hasPermissionTo('funding-agreements.create'))->toBeTrue()
        ->and($role->hasPermissionTo('funding-agreements.update'))->toBeTrue()
        ->and($role->hasPermissionTo('funding-agreements.delete'))->toBeFalse();
});

test('the migration changes role grants only, never a hand-added or direct permission', function () {
    Role::findByName('Managers', 'web')->givePermissionTo('risks.create');
    $user = User::factory()->role('Managers')->create()->givePermissionTo('targets.create');

    $this->lifecycleMigration->up();

    expect(Role::findByName('Managers', 'web')->hasPermissionTo('risks.create'))->toBeTrue()
        ->and($user->fresh()->getDirectPermissions()->pluck('name')->all())->toBe(['targets.create']);
});

test('running the migration twice changes nothing more', function () {
    $this->lifecycleMigration->up();
    $before = Role::findByName('Public Relations Manager', 'web')->permissions()->pluck('name')->sort()->values()->all();

    $this->lifecycleMigration->up();

    expect(Role::findByName('Public Relations Manager', 'web')->permissions()->pluck('name')->sort()->values()->all())->toBe($before);
});

test('rolling the migration back restores the earlier grants', function () {
    $this->lifecycleMigration->up();
    $this->lifecycleMigration->down();

    expect(Role::findByName('General Manager', 'web')->hasAllPermissions(['targets.create', 'targets.update']))->toBeTrue()
        ->and(Role::findByName('Managers', 'web')->hasAllPermissions(['targets.create', 'targets.update']))->toBeTrue()
        ->and(Role::findByName('Public Relations Manager', 'web')->hasAnyPermission(['funding-agreements.view', 'funding-agreements.create', 'funding-agreements.update']))->toBeFalse();
});
