<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the full access-control permission catalog and the 8 application
 * roles, each with its config('access-control.role_baselines') starting
 * permission set. Idempotent: safe to run more than once.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('access-control.groups') as $module => $actions) {
            foreach ($actions as $action) {
                Permission::query()->firstOrCreate([
                    'name' => "{$module}.{$action}",
                    'guard_name' => 'web',
                ]);
            }
        }

        $allPermissions = Permission::all();

        foreach (config('access-control.role_baselines') as $roleName => $permissionNames) {
            $role = Role::query()->firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $role->syncPermissions($permissionNames);
        }

        foreach (config('access-control.administrator_roles') as $roleName) {
            $role = Role::query()->firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $role->syncPermissions($allPermissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
