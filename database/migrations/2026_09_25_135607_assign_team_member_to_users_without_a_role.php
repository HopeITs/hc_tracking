<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Give every account that has no role the default Team Member role, once.
     *
     * A migration rather than the seeder so it runs exactly once: an admin who
     * later removes every role from someone must not see it silently restored.
     * The role's permissions come from RolesAndPermissionsSeeder, which is run
     * after migrating.
     */
    public function up(): void
    {
        Activity::withoutRecording(function (): void {
            $teamMember = Role::query()->firstOrCreate(['name' => 'Team Member', 'guard_name' => 'web']);

            // Attach through the relation: assignRole() reads $user->roles, a lazy load strict mode refuses.
            User::query()->whereDoesntHave('roles')->each(fn (User $user) => $user->roles()->attach($teamMember->id));
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Not reversible: a later, deliberate Team Member assignment cannot be told
     * apart from this backfill.
     */
    public function down(): void {}
};
