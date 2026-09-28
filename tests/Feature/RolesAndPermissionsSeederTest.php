<?php

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\RolesAndPermissionsSeeder;

test('the seeder creates one permission per module action and all 9 roles', function () {
    (new RolesAndPermissionsSeeder)->run();

    foreach (config('access-control.groups') as $module => $actions) {
        foreach ($actions as $action) {
            expect(Permission::query()->where('name', "{$module}.{$action}")->exists())->toBeTrue();
        }
    }

    expect(Role::query()->pluck('name')->all())->toEqualCanonicalizing([
        'Financial', 'Media Team', 'MEAL', 'Public Relations',
        'Public Relations Manager', 'Managers', 'Super Admin', 'General Manager', 'Team Member',
    ]);
});

test('the seeder gives Super Admin every seeded permission', function () {
    (new RolesAndPermissionsSeeder)->run();

    $superAdmin = Role::query()->where('name', 'Super Admin')->sole();
    $totalPermissions = Permission::query()->count();

    expect($superAdmin->permissions()->count())->toBe($totalPermissions);
});

test('running the seeder twice does not duplicate roles or permissions', function () {
    (new RolesAndPermissionsSeeder)->run();
    (new RolesAndPermissionsSeeder)->run();

    expect(Role::query()->count())->toBe(9)
        ->and(Permission::query()->count())->toBe(
            collect(config('access-control.groups'))->flatten()->count()
        );
});
