<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * The fundraising officer opens, writes and moves proposals (user story §1)
     * and can read the donor list. Donors are still recorded by the Public
     * Relations Manager and approved by the General Manager, so donors.create
     * is not here. These permissions already exist, so `permissions:sync`
     * (which only hands out new permissions) would never reach the role.
     *
     * @var list<string>
     */
    private const array Granted = ['proposals.view', 'proposals.create', 'proposals.update', 'donors.view'];

    public function up(): void
    {
        $this->change(true);
    }

    /**
     * Takes back exactly what up() grants.
     */
    public function down(): void
    {
        $this->change(false);
    }

    private function change(bool $grant): void
    {
        $role = Role::query()->where('name', 'Public Relations')->where('guard_name', 'web')->first();

        if ($role !== null) {
            $ids = Permission::query()->whereIn('name', self::Granted)->where('guard_name', 'web')->pluck('id');

            $grant ? $role->permissions()->syncWithoutDetaching($ids) : $role->permissions()->detach($ids);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
