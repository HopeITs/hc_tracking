<?php

use App\Models\Permission;
use App\Models\Role;

test('the permission catalog config drives permission names', function () {
    expect(config('access-control.groups'))->toHaveKey('programs')
        ->and(config('access-control.groups.programs'))->toBe(['view', 'create', 'update', 'delete']);
});

test('creating the Super Admin role grants it every permission that exists', function () {
    // A "Super Admin" row may already exist from the data migration that
    // assigns it to known accounts (2026_09_18_154600_...); delete it first
    // so this test observes a genuine `created` event, not a no-op findOrCreate.
    Role::query()->where('name', 'Super Admin')->delete();

    Permission::query()->create(['name' => 'programs.view', 'guard_name' => 'web']);
    Permission::query()->create(['name' => 'programs.delete', 'guard_name' => 'web']);

    $role = Role::query()->create(['name' => 'Super Admin', 'guard_name' => 'web']);

    expect($role->fresh()->permissions->pluck('name')->all())
        ->toEqualCanonicalizing(['programs.view', 'programs.delete']);
});

test('creating a configured role grants exactly its baseline', function () {
    Permission::query()->create(['name' => 'reports.view', 'guard_name' => 'web']);
    Permission::query()->create(['name' => 'reports.create', 'guard_name' => 'web']);
    Permission::query()->create(['name' => 'reports.update', 'guard_name' => 'web']);
    Permission::query()->create(['name' => 'programs.view', 'guard_name' => 'web']);
    Permission::query()->create(['name' => 'projects.view', 'guard_name' => 'web']);
    Permission::query()->create(['name' => 'calendar.view', 'guard_name' => 'web']);
    Permission::query()->create(['name' => 'tasks.view', 'guard_name' => 'web']);
    Permission::query()->create(['name' => 'tasks.create', 'guard_name' => 'web']);
    Permission::query()->create(['name' => 'tasks.update', 'guard_name' => 'web']);

    $role = Role::query()->create(['name' => 'Media Team', 'guard_name' => 'web']);

    expect($role->fresh()->permissions->pluck('name')->all())
        ->toEqualCanonicalizing(config('access-control.role_baselines.Media Team'));
});

test('creating an unconfigured role grants nothing automatically', function () {
    $role = Role::query()->create(['name' => 'Whatever', 'guard_name' => 'web']);

    expect($role->fresh()->permissions)->toBeEmpty();
});
