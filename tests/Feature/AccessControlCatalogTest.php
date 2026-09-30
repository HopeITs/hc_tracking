<?php

use App\Models\Permission;
use App\Models\Role;

test('the permission catalog config drives permission names', function () {
    expect(config('access-control.groups'))->toHaveKey('programs')
        ->and(config('access-control.groups.programs'))->toBe(['view', 'create', 'update', 'delete']);
});

test('creating the Super Admin role grants it every permission that exists', function () {
    // The seeded catalog already has a "Super Admin" row; delete it so this
    // test observes a genuine `created` event, not a no-op findOrCreate.
    Role::query()->where('name', 'Super Admin')->delete();

    $role = Role::query()->create(['name' => 'Super Admin', 'guard_name' => 'web']);

    expect($role->fresh()->permissions->pluck('name')->all())
        ->toEqualCanonicalizing(Permission::query()->pluck('name')->all());
});

test('creating a configured role grants exactly its baseline', function () {
    Role::query()->where('name', 'Media Team')->delete();

    $role = Role::query()->create(['name' => 'Media Team', 'guard_name' => 'web']);

    expect($role->fresh()->permissions->pluck('name')->all())
        ->toEqualCanonicalizing(config('access-control.role_baselines.Media Team'));
});

test('creating an unconfigured role grants nothing automatically', function () {
    $role = Role::query()->create(['name' => 'Whatever', 'guard_name' => 'web']);

    expect($role->fresh()->permissions)->toBeEmpty();
});
