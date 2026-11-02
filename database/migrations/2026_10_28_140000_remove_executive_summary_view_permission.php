<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Roles that held executive-summary.view in their baseline.
     */
    private const array PreviousViewers = ['Super Admin', 'General Manager', 'Financial'];

    /**
     * The executive summary page is gone, so its permission goes too:
     * permissions:sync only ever adds, and would leave a checkbox on the
     * roles tab that grants nothing.
     */
    public function up(): void
    {
        Permission::query()->where('name', 'executive-summary.view')->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permission = Permission::query()->firstOrCreate(['name' => 'executive-summary.view', 'guard_name' => 'web']);

        Role::query()
            ->with('permissions')
            ->whereIn('name', self::PreviousViewers)
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permission));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
