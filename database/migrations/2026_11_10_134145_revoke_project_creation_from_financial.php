<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Adding a center belongs to the Public Relations Manager and the General
     * Manager (CEO) only (Managers UAT, 2026-11-10). Financial was given
     * projects.create in W-34a so that approving a proposal could create its
     * projects, but since D-70 only the Public Relations Manager approves, so
     * Financial no longer needs it. `permissions:sync` only adds, hence this.
     */
    public function up(): void
    {
        $permission = $this->projectCreation();

        if ($permission !== null) {
            $this->financial()?->revokePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permission = $this->projectCreation();

        if ($permission !== null) {
            $this->financial()?->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function financial(): ?Role
    {
        return Role::query()->where('name', 'Financial')->where('guard_name', 'web')->first();
    }

    private function projectCreation(): ?Permission
    {
        return Permission::query()->where('name', 'projects.create')->where('guard_name', 'web')->first();
    }
};
