<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Admin;

use App\Models\HrUser;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * The Users tab of admin/users: the directory query and its lookups, picking a
 * user, saving that user's roles, active role and direct permissions, adding a
 * user from an HR account, and deleting one. Every action authorizes itself
 * through UserPolicy (viewAny, create, update, delete, assignRole,
 * grantPermission) before it touches data.
 *
 * Used by admin/users only. The page provides the filter properties below;
 * the selection and form properties (selectedUserId and deletingUserId are
 * #[Locked]) live in this trait. It calls no other trait.
 *
 * @property string $search
 * @property string $role
 * @property string $status
 * @property-read LengthAwarePaginator<int, User> $users  #[Computed] users()
 * @property-read Collection<int, Role> $roleOptions  #[Computed] roleOptions()
 * @property-read User|null $selectedUser  #[Computed] selectedUser()
 * @property-read array<int, array{label: string, permissions: Collection<int, Permission>}> $permissionGroups  #[Computed] permissionGroups()
 * @property-read Collection<int, HrUser> $hrAccountOptions  #[Computed] hrAccountOptions()
 */
trait ManagesUserAccess
{
    #[Locked]
    public ?int $selectedUserId = null;

    /** @var array<int, int> */
    public array $userRoleIds = [];

    public ?int $userActiveRoleId = null;

    /** @var array<int, int> */
    public array $userPermissionIds = [];

    public bool $showCreateUserModal = false;

    public bool $showDeleteModal = false;

    public string $hrUserId = '';

    public string $initialRoleId = '';

    #[Locked]
    public ?int $deletingUserId = null;

    public string $deletingUserName = '';

    public ?string $deleteError = null;

    /**
     * @return LengthAwarePaginator<int, User>
     */
    #[Computed]
    public function users(): LengthAwarePaginator
    {
        Gate::authorize('viewAny', User::class);

        return User::query()
            ->with(['roles', 'employee'])
            ->when($this->search !== '', fn ($query) => $query->whereNameOrEmailLike($this->search))
            ->when($this->role !== '', fn ($query) => $query->whereHas('roles', fn ($q) => $q->where('name', $this->role)))
            ->when($this->status === 'active', fn ($query) => $query->withActiveHrAccount())
            ->when($this->status === 'inactive', fn ($query) => $query->withoutActiveHrAccount())
            ->orderByName()
            ->paginate($this->selectedUserId ? 24 : 12);
    }

    /**
     * @return Collection<int, Role>
     */
    #[Computed]
    public function roleOptions(): Collection
    {
        return Role::query()->with('permissions')->orderBy('name')->get();
    }

    #[Computed]
    public function selectedUser(): ?User
    {
        return $this->selectedUserId === null
            ? null
            : User::query()->with(['roles', 'permissions', 'employee'])->find($this->selectedUserId);
    }

    /**
     * Permissions grouped by module, in config('access-control.groups') order.
     *
     * @return array<int, array{label: string, permissions: Collection<int, Permission>}>
     */
    #[Computed]
    public function permissionGroups(): array
    {
        $permissionsByName = Permission::all()->keyBy('name');
        $groups = [];

        /** @var list<string> $actions */
        foreach (config('access-control.groups') as $module => $actions) {
            $permissions = collect($actions)
                ->map(fn (string $action) => $permissionsByName->get("{$module}.{$action}"))
                ->filter();

            if ($permissions->isEmpty()) {
                continue;
            }

            $groups[] = [
                'label' => __('access.modules.'.$module),
                'permissions' => $permissions->values(),
            ];
        }

        return $groups;
    }

    /**
     * Active HR login accounts that do not have a tracking user yet.
     *
     * @return Collection<int, HrUser>
     */
    #[Computed]
    public function hrAccountOptions(): Collection
    {
        Gate::authorize('create', User::class);

        $linkedHrUserIds = User::query()->pluck('hr_user_id')->flip();

        return HrUser::query()
            ->active()
            ->select(['id', 'employee_id', 'email'])
            ->with('employee:id,first_name_en,last_name_en,first_name_ar,last_name_ar')
            ->orderBy('email')
            ->get()
            ->reject(fn (HrUser $hrUser): bool => $linkedHrUserIds->has($hrUser->id))
            ->values();
    }

    public function selectUser(int $userId): void
    {
        Gate::authorize('viewAny', User::class);

        $user = User::query()->with(['roles', 'permissions'])->findOrFail($userId);

        $this->selectedUserId = $user->id;
        $this->userRoleIds = $user->roles->pluck('id')->all();
        $this->userActiveRoleId = $user->active_role_id ?? ($this->userRoleIds[0] ?? null);
        $this->userPermissionIds = $user->permissions->pluck('id')->all();
    }

    public function updatedUserRoleIds(): void
    {
        $this->userRoleIds = array_values(array_unique(array_map('intval', $this->userRoleIds)));

        if (! in_array($this->userActiveRoleId, $this->userRoleIds, true)) {
            $this->userActiveRoleId = $this->userRoleIds[0] ?? null;
        }
    }

    /**
     * Save the selected user's role assignments, active role, and direct
     * permission overrides in one action.
     */
    public function saveUserAccess(): void
    {
        $user = User::query()->findOrFail($this->selectedUserId);

        Gate::authorize('update', $user);

        $validated = $this->validate([
            'userRoleIds' => ['array'],
            'userRoleIds.*' => ['integer', 'distinct', Rule::exists('roles', 'id')],
            'userActiveRoleId' => ['nullable', 'integer', Rule::in($this->userRoleIds)],
            'userPermissionIds' => ['array'],
            'userPermissionIds.*' => ['integer', 'distinct', Rule::exists('permissions', 'id')],
        ]);

        $this->authorizeAccessChanges($user, $validated['userRoleIds'], $validated['userPermissionIds']);

        $rolesBefore = $user->roles()->pluck('name')->all();
        $user->roles()->sync($validated['userRoleIds']);
        $user->recordRoleChange($rolesBefore, $user->roles()->pluck('name')->all());
        $user->setActiveRole($validated['userActiveRoleId']);
        $permissionsBefore = $user->permissions()->pluck('name')->all();
        $user->syncPermissions(Permission::query()->whereIn('id', $validated['userPermissionIds'])->get());
        $user->recordPermissionChange($permissionsBefore, $user->permissions()->pluck('name')->all());

        Flux::toast(variant: 'success', text: __('users.user_updated'));

        $this->selectUser($user->id);
    }

    /**
     * Every role and direct permission being added or removed must be one the
     * actor may hand out: user-management rights stay with administrators.
     *
     * @param  array<int, int|string>  $roleIds
     * @param  array<int, int|string>  $permissionIds
     */
    protected function authorizeAccessChanges(User $user, array $roleIds, array $permissionIds): void
    {
        $rolesBefore = $user->roles()->pluck('roles.id')->map(fn (mixed $id): int => (int) $id)->all();
        $rolesAfter = array_map('intval', $roleIds);

        Role::query()
            ->whereKey(array_merge(array_diff($rolesAfter, $rolesBefore), array_diff($rolesBefore, $rolesAfter)))
            ->with('permissions')
            ->get()
            ->each(fn (Role $role) => Gate::authorize('assignRole', [User::class, $role]));

        $permissionsBefore = $user->permissions()->pluck('permissions.id')->map(fn (mixed $id): int => (int) $id)->all();
        $permissionsAfter = array_map('intval', $permissionIds);

        Permission::query()
            ->whereKey(array_merge(array_diff($permissionsAfter, $permissionsBefore), array_diff($permissionsBefore, $permissionsAfter)))
            ->get()
            ->each(fn (Permission $permission) => Gate::authorize('grantPermission', [User::class, $permission]));
    }

    public function openCreateModal(): void
    {
        Gate::authorize('create', User::class);

        $this->resetCreateUserForm();
        $this->showCreateUserModal = true;
    }

    public function closeCreateModal(): void
    {
        $this->showCreateUserModal = false;
        $this->resetCreateUserForm();
    }

    /**
     * Give an HR account access to hc-tracking, with an optional starting role.
     */
    public function createUser(): void
    {
        Gate::authorize('create', User::class);

        /** @var array{hrUserId: string, initialRoleId: string|null} $validated */
        $validated = $this->validate([
            'hrUserId' => ['required', 'integer', Rule::exists(HrUser::class, 'id')],
            'initialRoleId' => ['nullable', 'integer', Rule::exists('roles', 'id')],
        ], [
            'hrUserId.required' => __('users.select_an_hr_account_2'),
            'hrUserId.exists' => __('users.the_selected_hr_account_is_no_longer_available'),
        ]);

        $hrUser = HrUser::query()->with('employee')->findOrFail((int) $validated['hrUserId']);

        if (! $hrUser->isActive()) {
            $this->addError('hrUserId', __('users.the_selected_hr_account_is_no_longer_available'));

            return;
        }

        if (User::query()->where('hr_user_id', $hrUser->id)->exists()) {
            $this->addError('hrUserId', __('users.this_hr_account_already_has_access'));

            return;
        }

        $roleName = $validated['initialRoleId'] !== null && $validated['initialRoleId'] !== ''
            ? Role::query()->find($validated['initialRoleId'])?->name
            : (string) config('access-control.default_role');

        $startingRole = $roleName === null ? null : Role::query()->with('permissions')->where('name', $roleName)->first();

        if ($startingRole !== null) {
            Gate::authorize('assignRole', [User::class, $startingRole]);
        }

        User::createFromHrAccount($hrUser, $roleName);

        $this->closeCreateModal();

        Flux::toast(variant: 'success', text: __('users.user_created'));
    }

    public function confirmUserDeletion(int $userId): void
    {
        $user = User::query()->findOrFail($userId);

        Gate::authorize('delete', $user);

        $this->deletingUserId = $user->id;
        $this->deletingUserName = $user->name;
        $this->deleteError = null;
        $this->showDeleteModal = true;
    }

    public function deleteUser(): void
    {
        $user = User::query()->findOrFail($this->deletingUserId);

        Gate::authorize('delete', $user);

        try {
            /** @throws QueryException */
            $user->delete();
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }

            $this->deleteError = __('users.this_user_is_linked_to_operational_records_and_cannot_be_del');

            Flux::toast(variant: 'danger', text: $this->deleteError);

            return;
        }

        if ($this->selectedUserId === $user->id) {
            $this->selectedUserId = null;
        }

        $this->closeDeleteModal();

        Flux::toast(variant: 'success', text: __('users.user_deleted'));
    }

    public function closeDeleteModal(): void
    {
        $this->showDeleteModal = false;
        $this->deletingUserId = null;
        $this->deletingUserName = '';
        $this->deleteError = null;
    }

    protected function resetCreateUserForm(): void
    {
        $this->resetValidation();
        $this->hrUserId = '';
        $this->initialRoleId = '';
    }
}
