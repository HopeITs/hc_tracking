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
