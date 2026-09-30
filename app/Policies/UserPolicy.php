<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Spatie\Permission\Models\Permission as SpatiePermission;

class UserPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('access-management.manage-users');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, User $targetUser): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can update the model.
     *
     * Nobody edits their own access, and only an administrator touches an
     * administrator's account.
     */
    public function update(User $user, User $targetUser): bool
    {
        return $this->viewAny($user)
            && ! $user->is($targetUser)
            && ($this->isAdministrator($user) || ! $this->isAdministrator($targetUser));
    }

    /**
     * Determine whether the user can delete the model. The last administrator
     * is never removable, so the system always keeps someone who can manage it.
     */
    public function delete(User $user, User $targetUser): bool
    {
        return $this->update($user, $targetUser) && ! $this->isLastAdministrator($targetUser);
    }

    /**
     * Whether the user may give or take away one role. Roles carrying
     * user-management rights (an administrator role, or any role holding an
     * access-management permission) are handed out only by administrators.
     */
    public function assignRole(User $user, Role $role): bool
    {
        return $this->viewAny($user) && ($this->isAdministrator($user) || ! self::isPrivileged($role));
    }

    /**
     * Whether the user may give or take away one direct permission;
     * access-management permissions need an administrator.
     */
    public function grantPermission(User $user, Permission $permission): bool
    {
        return $this->viewAny($user)
            && ($this->isAdministrator($user) || ! str_starts_with($permission->name, 'access-management.'));
    }

    /**
     * Whether the user may rename or delete one role. Built-in roles
     * (isBuiltIn()) are never renamed or deleted; a non-administrator may not touch a role they
     * hold themselves or one carrying user-management rights.
     */
    public function manageRole(User $user, Role $role): bool
    {
        return $user->can('access-management.manage-roles')
            && ! self::isBuiltIn($role)
            && $this->mayReshapeRole($user, $role);
    }

    /**
     * Whether a role is one of the built-in roles named in
     * config/access-control.php. Rules find these by name (task department
     * scoping, the starting role, alert routing), so renaming or deleting one
     * would silently break them; their permissions stay editable.
     */
    public static function isBuiltIn(Role $role): bool
    {
        return in_array($role->name, [
            ...array_keys(config('access-control.role_baselines', [])),
            ...config('access-control.administrator_roles', []),
        ], true);
    }

    /**
     * Whether the user may change which permissions one role holds, under the
     * same limits as manageRole(). Each access-management permission being
     * added or removed is checked separately through grantPermission().
     */
    public function editRolePermissions(User $user, Role $role): bool
    {
        return $user->can('access-management.manage-permissions') && $this->mayReshapeRole($user, $role);
    }

    /**
     * Shared limits for changing a role: administrator roles stay fixed, and
     * only administrators change their own role or a privileged one — so
     * nobody can promote themselves through the roles they belong to.
     */
    private function mayReshapeRole(User $user, Role $role): bool
    {
        if (in_array($role->name, config('access-control.administrator_roles', []), true)) {
            return false;
        }

        return $this->isAdministrator($user)
            || (! $user->hasRole($role) && ! self::isPrivileged($role));
    }

    /**
     * Whether a role carries user-management rights.
     */
    public static function isPrivileged(Role $role): bool
    {
        return in_array($role->name, config('access-control.administrator_roles', []), true)
            || $role->permissions->contains(fn (SpatiePermission $permission): bool => str_starts_with($permission->name, 'access-management.'));
    }

    /**
     * Whether the user holds an administrator role.
     */
    private function isAdministrator(User $user): bool
    {
        return $user->hasAnyRole(config('access-control.administrator_roles', []));
    }

    /**
     * Whether the user is the only remaining administrator.
     */
    private function isLastAdministrator(User $user): bool
    {
        return $this->isAdministrator($user)
            && User::role(config('access-control.administrator_roles', []))->count() <= 1;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, User $targetUser): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, User $targetUser): bool
    {
        return false;
    }
}
