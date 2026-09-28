<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Roles that could see the activity log before it became a permission.
     */
    private const array PreviousViewers = ['Super Admin', 'General Manager'];

    /**
     * Turn the role-name gate on the activity log into the activity-log.view
     * permission, handed to exactly the roles the old gate let in, so nobody
     * gains or loses access on deploy. From here on it is a normal checkbox
     * in Admin > Users > Roles, and renaming a role no longer locks it out.
     */
    public function up(): void
    {
        $permission = Permission::query()->firstOrCreate(['name' => 'activity-log.view', 'guard_name' => 'web']);

        Role::query()
            ->with('permissions')
            ->whereIn('name', self::PreviousViewers)
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permission));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'activity-log.view')->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
