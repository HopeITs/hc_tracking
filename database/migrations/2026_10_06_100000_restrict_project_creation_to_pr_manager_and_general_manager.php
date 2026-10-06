<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Adding a project moves from Managers to Public Relations Manager; the
     * General Manager and Super Admin already hold every permission.
     */
    public function up(): void
    {
        $this->move(from: 'Managers', to: 'Public Relations Manager');
    }

    public function down(): void
    {
        $this->move(from: 'Public Relations Manager', to: 'Managers');
    }

    private function move(string $from, string $to): void
    {
        $permission = Permission::query()->where('name', 'projects.create')->where('guard_name', 'web')->first();

        if ($permission !== null) {
            Role::query()->where('name', $from)->where('guard_name', 'web')->first()?->permissions()->detach($permission->id);
            Role::query()->where('name', $to)->where('guard_name', 'web')->first()?->permissions()->syncWithoutDetaching([$permission->id]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
