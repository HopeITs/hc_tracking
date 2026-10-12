<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Admin;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Policies\UserPolicy;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;

/**
 * The Roles tab of admin/users: the role directory and the selected role, what
 * the viewer may do with it (UserPolicy::manageRole, editRolePermissions,
 * grantPermission), creating, renaming and deleting roles, editing a role's
 * permissions, and the assigned-users modal. Every action authorizes itself
 * through the Gate before it touches data.
 *
 * Used by admin/users only. The page provides the two properties below
 * (selectedRoleId is #[Locked]; both are read by page methods) and its
 * mount() calls loadSelectedRole(). The other role form properties live in
 * this trait. It calls no other trait.
 *
 * @property int|null $selectedRoleId
 * @property bool $showAssignedUsersModal
 * @property-read EloquentCollection<int, Role> $roles  #[Computed] roles()
 * @property-read Role|null $selectedRole  #[Computed] selectedRole()
 * @property-read bool $selectedRoleIsProtected  #[Computed] selectedRoleIsProtected()
 * @property-read bool $selectedRoleIsBuiltIn  #[Computed] selectedRoleIsBuiltIn()
 * @property-read bool $canManageSelectedRole  #[Computed] canManageSelectedRole()
 * @property-read bool $canEditSelectedRolePermissions  #[Computed] canEditSelectedRolePermissions()
 * @property-read array<int, int> $grantablePermissionIds  #[Computed] grantablePermissionIds()
 * @property-read EloquentCollection<int, User> $selectedRoleUsers  #[Computed] selectedRoleUsers()
 */
trait ManagesRoles
{
    /** @var array<int, int> */
    public array $rolePermissionIds = [];

    public string $newRoleName = '';

    public string $selectedRoleName = '';

    /**
     * @return EloquentCollection<int, Role>
     */
    #[Computed]
    public function roles(): EloquentCollection
    {
        return Role::query()->withCount('users')->orderBy('name')->get();
    }

    #[Computed]
    public function selectedRole(): ?Role
    {
        return $this->selectedRoleId === null
            ? null
            : Role::query()->withCount('users')->find($this->selectedRoleId);
    }

    #[Computed]
    public function selectedRoleIsProtected(): bool
    {
        return in_array($this->selectedRole?->name, config('access-control.administrator_roles', []), true);
    }

    /**
     * Whether the selected role is a built-in role whose name is fixed
     * (UserPolicy::isBuiltIn()); its permissions stay editable.
     */
    #[Computed]
    public function selectedRoleIsBuiltIn(): bool
    {
        return $this->selectedRole !== null && UserPolicy::isBuiltIn($this->selectedRole);
    }

    /**
     * Whether the viewer may rename or delete the selected role
     * (UserPolicy::manageRole).
     */
    #[Computed]
    public function canManageSelectedRole(): bool
    {
        $role = $this->selectedRole?->loadMissing('permissions');

        return $role !== null && Gate::allows('manageRole', [User::class, $role]);
    }

    /**
     * Whether the viewer may change the selected role's permissions
     * (UserPolicy::editRolePermissions).
     */
    #[Computed]
    public function canEditSelectedRolePermissions(): bool
    {
        $role = $this->selectedRole?->loadMissing('permissions');

        return $role !== null && Gate::allows('editRolePermissions', [User::class, $role]);
    }

    /**
     * Ids of the permissions the viewer may give or take away
     * (UserPolicy::grantPermission).
     *
     * @return array<int, int>
     */
    #[Computed]
    public function grantablePermissionIds(): array
    {
        return Permission::query()->get()
            ->filter(fn (Permission $permission): bool => Gate::allows('grantPermission', [User::class, $permission]))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return EloquentCollection<int, User>
     */
    #[Computed]
    public function selectedRoleUsers(): EloquentCollection
    {
        if (! $this->showAssignedUsersModal || ! $this->selectedRole) {
            return new EloquentCollection;
        }

        return $this->selectedRole->users()->get()->sortBy(fn (User $user): string => mb_strtolower($user->name))->values();
    }

    public function selectRole(int $roleId): void
    {
        Gate::authorize('viewAny', User::class);

        $this->selectedRoleId = Role::query()->whereKey($roleId)->value('id');
        abort_unless($this->selectedRoleId, 404);
        $this->showAssignedUsersModal = false;
        $this->loadSelectedRole();
    }

    public function showAssignedUsers(): void
    {
        abort_unless($this->selectedRoleId, 404);
        $this->showAssignedUsersModal = true;
    }

    public function closeAssignedUsersModal(): void
    {
        $this->showAssignedUsersModal = false;
    }

    public function createRole(): void
    {
        Gate::authorize('access-management.manage-roles');

        $validated = $this->validate([
            'newRoleName' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')],
        ]);

        $role = Role::query()->create(['name' => $validated['newRoleName'], 'guard_name' => 'web']);
        $role->recordActivity('role.created');

        $this->newRoleName = '';
        $this->selectRole($role->id);

        Flux::toast(variant: 'success', text: __('roles.role_created'));
    }

    public function renameRole(): void
    {
        $role = Role::query()->with('permissions')->findOrFail($this->selectedRoleId);

        Gate::authorize('manageRole', [User::class, $role]);

        $validated = $this->validate([
            'selectedRoleName' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')->ignore($role->id)],
        ]);

        $oldName = $role->name;
        $role->update(['name' => $validated['selectedRoleName']]);

        if ($oldName !== $role->name) {
            $role->recordActivity('role.renamed', ['name' => [$oldName, $role->name]], ['field' => 'name']);
        }

        Flux::toast(variant: 'success', text: __('roles.role_renamed'));
    }

    public function deleteRole(): void
    {
        $role = Role::query()->with('permissions')->findOrFail($this->selectedRoleId);

        Gate::authorize('manageRole', [User::class, $role]);

        $role->recordActivity('role.deleted');
        $role->delete();

        $this->selectedRoleId = Role::query()->orderBy('name')->value('id');
        $this->loadSelectedRole();

        Flux::toast(variant: 'success', text: __('roles.role_deleted'));
    }

    /**
     * Tick every permission the actor may hand out. Permissions the actor may
     * not grant (access-management.* for a non-administrator) keep the state
     * they already had.
     */
    public function selectAllRolePermissions(): void
    {
        Gate::authorize('editRolePermissions', [User::class, $this->roleForPermissionEdit()]);

        $current = array_map('intval', $this->rolePermissionIds);

        $this->rolePermissionIds = Permission::query()->get()
            ->filter(fn (Permission $permission): bool => Gate::allows('grantPermission', [User::class, $permission])
                || in_array($permission->id, $current, true))
            ->pluck('id')
            ->all();
    }

    /**
     * Untick every permission the actor may take away.
     */
    public function clearRolePermissions(): void
    {
        Gate::authorize('editRolePermissions', [User::class, $this->roleForPermissionEdit()]);

        $this->rolePermissionIds = Permission::query()
            ->whereKey($this->rolePermissionIds)
            ->get()
            ->reject(fn (Permission $permission): bool => Gate::allows('grantPermission', [User::class, $permission]))
            ->pluck('id')
            ->all();
    }

    public function saveRolePermissions(): void
    {
        $role = $this->roleForPermissionEdit();

        Gate::authorize('editRolePermissions', [User::class, $role]);

        $validated = $this->validate([
            'rolePermissionIds' => ['array'],
            'rolePermissionIds.*' => ['integer', 'distinct', Rule::exists('permissions', 'id')],
        ]);

        $before = $role->permissions->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $after = array_map('intval', $validated['rolePermissionIds']);

        $changed = Permission::query()
            ->whereKey(array_merge(array_diff($after, $before), array_diff($before, $after)))
            ->get();

        $changed->each(fn (Permission $permission) => Gate::authorize('grantPermission', [User::class, $permission]));

        $role->syncPermissions(Permission::query()->whereKey($after)->get());

        if ($changed->isNotEmpty()) {
            $role->recordActivity('role.permissions_changed', properties: $this->permissionChangeProperties($changed, $after));
        }

        Flux::toast(variant: 'success', text: __('roles.save_permissions'));
        $this->loadSelectedRole();
    }

    /**
     * The selected role with the permissions UserPolicy::isPrivileged() reads.
     */
    protected function roleForPermissionEdit(): Role
    {
        return Role::query()->with('permissions')->findOrFail($this->selectedRoleId);
    }

    /**
     * Sentence properties for a permission change: what was added and removed.
     *
     * @param  EloquentCollection<int, Permission>  $changed
     * @param  array<int>  $after
     * @return array{added: string, removed: string, added_permissions: array<int, string>, removed_permissions: array<int, string>}
     */
    protected function permissionChangeProperties(EloquentCollection $changed, array $after): array
    {
        [$added, $removed] = $changed->partition(fn (Permission $permission): bool => in_array($permission->id, $after, true));

        $addedNames = $added->pluck('name')->sort()->values()->all();
        $removedNames = $removed->pluck('name')->sort()->values()->all();

        return [
            'added' => $addedNames === [] ? '—' : implode(', ', $addedNames),
            'removed' => $removedNames === [] ? '—' : implode(', ', $removedNames),
            'added_permissions' => $addedNames,
            'removed_permissions' => $removedNames,
        ];
    }

    protected function loadSelectedRole(): void
    {
        if (! $this->selectedRoleId) {
            $this->selectedRoleName = '';
            $this->rolePermissionIds = [];

            return;
        }

        $role = Role::query()->with('permissions')->findOrFail($this->selectedRoleId);
        $this->selectedRoleName = $role->name;
        $this->rolePermissionIds = $role->permissions->pluck('id')->all();
        $this->resetValidation();
    }
}
