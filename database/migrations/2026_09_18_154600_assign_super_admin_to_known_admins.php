<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * The only two accounts holding UserRole::Admin at the time this system
     * was built. Every other user (all currently Officer) is deliberately
     * left with no role — see docs/superpowers/specs/2026-09-18-roles-and-permissions-design.md.
     */
    public function up(): void
    {
        $superAdmin = Role::query()->firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);

        User::query()
            ->whereIn('email', ['mai.youssef@hcsyria.org', 'youssef.kharbout@hcsyria.org'])
            ->with('roles')
            ->get()
            ->each(fn (User $user) => $user->assignRole($superAdmin));
    }

    public function down(): void
    {
        User::query()
            ->whereIn('email', ['mai.youssef@hcsyria.org', 'youssef.kharbout@hcsyria.org'])
            ->get()
            ->each(fn (User $user) => $user->removeRole('Super Admin'));
    }
};
