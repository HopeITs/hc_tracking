<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Activity;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Brings the database up to date with config/access-control.php without
 * undoing anything changed from the Admin > Users roles tab.
 *
 * RolesAndPermissionsSeeder re-syncs every role to its baseline, which wipes
 * those changes, so it is only for a fresh database. This command only ever
 * adds: missing permissions, missing roles (created with their baseline), each
 * new permission for the roles whose baseline lists it, and every permission
 * for the administrator roles. It never removes a permission from a role.
 */
class SyncPermissions extends Command
{
    /**
     * @var string
     */
    protected $signature = 'permissions:sync {--dry-run : List what would be added without changing anything}';

    /**
     * @var string
     */
    protected $description = 'Add new permissions and roles from the access-control config without undoing role changes made in the app';

    public function handle(PermissionRegistrar $registrar): int
    {
        $administratorRoles = config('access-control.administrator_roles', []);
        $baselines = config('access-control.role_baselines', []);

        $existingPermissions = Permission::query()->where('guard_name', 'web')->pluck('name')->all();
        $newPermissions = array_values(array_diff($this->catalog(), $existingPermissions));
        $allPermissions = [...$existingPermissions, ...$newPermissions];

        $roleNames = array_values(array_unique([...array_keys($baselines), ...$administratorRoles]));
        $newRoles = array_values(array_diff($roleNames, Role::query()->where('guard_name', 'web')->pluck('name')->all()));
        $grants = $this->grantsForExistingRoles($roleNames, $administratorRoles, $newPermissions, $allPermissions);

        foreach ($newPermissions as $permission) {
            $this->line("Permission added: {$permission}");
        }

        foreach ($newRoles as $role) {
            $this->line("Role added: {$role}");
        }

        foreach ($grants as $role => $permissions) {
            $this->line("{$role} gains: ".implode(', ', $permissions));
        }

        if ($newPermissions === [] && $newRoles === [] && $grants === []) {
            $this->components->info('Permissions are already up to date.');

            return self::SUCCESS;
        }

        if ((bool) $this->option('dry-run')) {
            $this->components->info('Dry run: nothing was changed.');

            return self::SUCCESS;
        }

        Activity::withoutRecording(fn () => DB::transaction(function () use ($newPermissions, $newRoles, $grants): void {
            foreach ($newPermissions as $permission) {
                Permission::query()->create(['name' => $permission, 'guard_name' => 'web']);
            }

            // Role::booted() grants a new role its baseline, or every
            // permission for an administrator role.
            foreach ($newRoles as $role) {
                Role::query()->create(['name' => $role, 'guard_name' => 'web']);
            }

            foreach ($grants as $role => $permissions) {
                Role::findByName($role, 'web')->givePermissionTo(Permission::query()->whereIn('name', $permissions)->get());
            }
        }));

        $registrar->forgetCachedPermissions();

        $this->components->info(count($newPermissions).' permission(s) and '.count($newRoles).' role(s) added; '.count($grants).' existing role(s) gained permissions.');

        return self::SUCCESS;
    }

    /**
     * Every "{module}.{action}" name in the permission catalog.
     *
     * @return list<string>
     */
    protected function catalog(): array
    {
        return collect(config('access-control.groups', []))
            ->flatMap(fn (array $actions, string $module): array => array_map(fn (string $action): string => "{$module}.{$action}", $actions))
            ->values()
            ->all();
    }

    /**
     * What each existing role is missing: administrator roles get every
     * permission; other roles only get new permissions their baseline lists,
     * so a permission someone removed on the roles tab stays removed.
     *
     * @param  list<string>  $roleNames
     * @param  list<string>  $administratorRoles
     * @param  list<string>  $newPermissions
     * @param  list<string>  $allPermissions
     * @return array<string, list<string>>
     */
    protected function grantsForExistingRoles(array $roleNames, array $administratorRoles, array $newPermissions, array $allPermissions): array
    {
        $grants = [];

        $roles = Role::query()->where('guard_name', 'web')->whereIn('name', $roleNames)->with('permissions')->get();

        foreach ($roles as $role) {
            $wanted = in_array($role->name, $administratorRoles, true)
                ? $allPermissions
                : array_intersect(config("access-control.role_baselines.{$role->name}", []), $newPermissions);

            $missing = array_values(array_diff($wanted, $role->permissions->pluck('name')->all()));

            if ($missing !== []) {
                $grants[$role->name] = $missing;
            }
        }

        return $grants;
    }
}
