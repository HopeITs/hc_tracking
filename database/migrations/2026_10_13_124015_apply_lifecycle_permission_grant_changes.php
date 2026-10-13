<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Console\Output\ConsoleOutput;

return new class extends Migration
{
    /**
     * Targets are entered by MEAL only (D-23): the roles that lose the grant.
     *
     * @var array<string, list<string>>
     */
    private const array Revoked = [
        'General Manager' => ['targets.create', 'targets.update'],
        'Managers' => ['targets.create', 'targets.update'],
    ];

    /**
     * Public Relations Manager works on funding agreements with Finance, but
     * never deletes one (D-31b).
     *
     * @var array<string, list<string>>
     */
    private const array Granted = [
        'Public Relations Manager' => ['funding-agreements.view', 'funding-agreements.create', 'funding-agreements.update'],
    ];

    /**
     * Apply the contracts §8 changes to permissions that already exist, which
     * `permissions:sync` (additive, new permissions only) cannot do. Only
     * these role grants change; direct user permissions and anything else
     * an admin edited on the roles tab are left alone.
     */
    public function up(): void
    {
        [$revoked, $granted] = DB::transaction(fn (): array => [$this->detach(self::Revoked), $this->attach(self::Granted)]);

        $this->report("Lifecycle permissions: {$revoked} role grant(s) revoked (targets.create/update), {$granted} granted (funding-agreements to Public Relations Manager).");
    }

    /**
     * Puts back exactly the grants up() changed: General Manager and Managers
     * enter targets again (even if an admin had removed it before up() ran),
     * and Public Relations Manager loses the funding agreement permissions.
     */
    public function down(): void
    {
        [$granted, $revoked] = DB::transaction(fn (): array => [$this->attach(self::Revoked), $this->detach(self::Granted)]);

        $this->report("Lifecycle permissions rolled back: {$granted} role grant(s) restored (targets.create/update), {$revoked} revoked (funding-agreements from Public Relations Manager).");
    }

    /**
     * @param  array<string, list<string>>  $grants
     */
    private function attach(array $grants): int
    {
        $count = 0;

        foreach ($grants as $roleName => $permissionNames) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            $permissionIds = Permission::query()->whereIn('name', $permissionNames)->where('guard_name', 'web')->pluck('id');

            $count += $role === null ? 0 : count($role->permissions()->syncWithoutDetaching($permissionIds)['attached']);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $count;
    }

    /**
     * @param  array<string, list<string>>  $grants
     */
    private function detach(array $grants): int
    {
        $count = 0;

        foreach ($grants as $roleName => $permissionNames) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            $permissionIds = Permission::query()->whereIn('name', $permissionNames)->where('guard_name', 'web')->pluck('id');

            $count += $role?->permissions()->detach($permissionIds) ?? 0;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $count;
    }

    /**
     * Print the count during `migrate`, but keep the test output clean.
     */
    private function report(string $message): void
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            (new ConsoleOutput)->writeln("  {$message}");
        }
    }
};
