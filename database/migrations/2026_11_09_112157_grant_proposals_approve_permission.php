<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Recording the donor's approval of a proposal belongs to the Public
     * Relations Manager only (D-70). Before this permission existed, approval
     * followed proposals.update, so no role can already hold it: the grant
     * below is all that is needed, and no other role loses anything it had.
     */
    private const array Holders = ['Super Admin', 'Public Relations Manager'];

    /**
     * Create the permission and hand it to its holders, so deploying the code
     * never leaves approval with nobody until `permissions:sync` is run.
     */
    public function up(): void
    {
        $permission = Permission::query()->firstOrCreate(['name' => 'proposals.approve', 'guard_name' => 'web']);

        Role::query()
            ->with('permissions')
            ->whereIn('name', self::Holders)
            ->where('guard_name', 'web')
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permission));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'proposals.approve')->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
