<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * What the Managers role loses: building the logframe is the MEAL
     * manager's alone (MEAL manager decision, 2026-11-13).
     */
    private const array ManagersLose = ['indicators.create', 'indicators.update', 'results.create', 'results.update'];

    /**
     * Logging an indicator reading used to follow `indicators.update`. It gets
     * its own permission, handed to every role that holds `indicators.update`
     * today, so nobody who logs readings loses that when editing indicators
     * is narrowed to the MEAL manager.
     */
    public function up(): void
    {
        $record = Permission::query()->firstOrCreate(['name' => 'indicators.record', 'guard_name' => 'web']);

        Role::query()
            ->with('permissions')
            ->where('guard_name', 'web')
            ->whereHas('permissions', fn ($query) => $query->where('name', 'indicators.update'))
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($record));

        $managers = Role::query()->where('name', 'Managers')->where('guard_name', 'web')->first();

        if ($managers !== null) {
            $managers->revokePermissionTo(
                Permission::query()->whereIn('name', self::ManagersLose)->where('guard_name', 'web')->get(),
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $managers = Role::query()->where('name', 'Managers')->where('guard_name', 'web')->first();

        if ($managers !== null) {
            $managers->givePermissionTo(
                Permission::query()->whereIn('name', self::ManagersLose)->where('guard_name', 'web')->get(),
            );
        }

        Permission::query()->where('name', 'indicators.record')->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
