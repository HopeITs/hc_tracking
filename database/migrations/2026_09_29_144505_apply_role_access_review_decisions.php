<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * The money pages each built-in role stops viewing, and the report
     * deletion Public Relations Manager gives up (access review, 2026-09-29).
     * Only these exact grants are taken back; anything else an admin changed
     * on the roles tab is left alone.
     *
     * @var array<string, list<string>>
     */
    private const array Revoked = [
        'Team Member' => self::MoneyViews,
        'Media Team' => self::MoneyViews,
        'Public Relations' => self::MoneyViews,
        'MEAL' => self::MoneyViews,
        'Managers' => self::MoneyViews,
        'Public Relations Manager' => ['transfers.view', 'funding-agreements.view', 'accounts.view', 'executive-summary.view', 'reports.delete'],
    ];

    /**
     * @var list<string>
     */
    private const array MoneyViews = ['transfers.view', 'funding-agreements.view', 'accounts.view', 'executive-summary.view', 'donors.view', 'proposals.view'];

    /**
     * Permissions nothing ever checked (cities and programs come from HR),
     * with the roles that held them, so down() can put them back.
     *
     * @var array<string, list<string>>
     */
    private const array Unused = [
        'cities.create' => ['Super Admin', 'General Manager'],
        'cities.update' => ['Super Admin', 'General Manager'],
        'cities.delete' => ['Super Admin', 'General Manager'],
        'programs.create' => ['Super Admin', 'General Manager', 'Managers'],
        'programs.delete' => ['Super Admin', 'General Manager'],
    ];

    /**
     * Apply the access review: money pages move to the finance-facing roles,
     * the unused permissions go, and the organisation notification settings
     * become a permission held by the administrator roles (so nobody gains or
     * loses that page on deploy).
     */
    public function up(): void
    {
        $notificationSettings = Permission::query()->firstOrCreate(['name' => 'notification-settings.manage', 'guard_name' => 'web']);

        Role::query()
            ->whereIn('name', config('access-control.administrator_roles', []))
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($notificationSettings));

        foreach (self::Revoked as $roleName => $permissionNames) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();

            $role?->permissions()->detach(Permission::query()->whereIn('name', $permissionNames)->pluck('id'));
        }

        Permission::query()->whereIn('name', array_keys(self::Unused))->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (self::Unused as $permissionName => $roleNames) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName, 'guard_name' => 'web']);

            Role::query()->whereIn('name', $roleNames)->get()->each(fn (Role $role) => $role->givePermissionTo($permission));
        }

        foreach (self::Revoked as $roleName => $permissionNames) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();

            $role?->permissions()->syncWithoutDetaching(Permission::query()->whereIn('name', $permissionNames)->pluck('id'));
        }

        Permission::query()->where('name', 'notification-settings.manage')->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
