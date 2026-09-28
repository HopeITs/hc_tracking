<?php

declare(strict_types=1);

namespace App\Models;

use App\Notifications\ActivityRecorder;
use App\Notifications\TracksActivity;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * A named collection of permissions a user is assigned.
 *
 * "Super Admin" (config('access-control.administrator_roles')) always holds
 * every permission, regardless of what config('access-control.role_baselines')
 * says — new modules must never leave it locked out. Every other role starts
 * with its configured baseline, editable afterward from the Roles &
 * Permissions admin page.
 *
 * Changes made from that page (create, rename, delete, permissions) are
 * recorded explicitly through recordActivity(); there are no automatic
 * model-event hooks, so seeders and tests creating roles log nothing.
 */
class Role extends SpatieRole implements TracksActivity
{
    protected static function booted(): void
    {
        static::created(function (Role $role): void {
            if (in_array($role->name, config('access-control.administrator_roles', []), true)) {
                $role->givePermissionTo(Permission::all());
            } else {
                $baseline = config("access-control.role_baselines.{$role->name}", []);

                if ($baseline !== []) {
                    // Only grant permissions that already exist. A role can be
                    // created (in a test, or via the admin UI) before every
                    // catalog permission has been seeded — silently skipping
                    // names that don't exist yet beats a hard failure.
                    $role->givePermissionTo(Permission::query()->whereIn('name', $baseline)->get());
                }
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        });
    }

    public function activityModule(): string
    {
        return 'access-management';
    }

    public function activityProgramId(): ?int
    {
        return null;
    }

    public function activityLabel(): string
    {
        return $this->name;
    }

    /**
     * @return list<string>
     */
    public function activityEmployeeFields(): array
    {
        return [];
    }

    /**
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes
     * @param  array<string, mixed>  $properties
     */
    public function recordActivity(string $event, array $changes = [], array $properties = []): ?Activity
    {
        return app(ActivityRecorder::class)->record($this, $event, $changes, $properties);
    }
}
