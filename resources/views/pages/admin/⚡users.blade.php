<?php

use App\Models\HrUser;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('titles.user_directory')] class extends Component {
    use WithPagination;

    #[Url(as: 'tab', except: 'users')]
    public string $activeTab = 'users';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $role = '';

    #[Url(except: '')]
    public string $status = '';

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
    public ?int $deletingUserId = null;
    public string $deletingUserName = '';
    public ?string $deleteError = null;

    public ?int $selectedRoleId = null;

    /** @var array<int, int> */
    public array $rolePermissionIds = [];

    public string $newRoleName = '';
    public string $selectedRoleName = '';
    public bool $showAssignedUsersModal = false;

    public function mount(): void
    {
        Gate::authorize('viewAny', User::class);

        $this->selectedRoleId = Role::query()->orderBy('name')->value('id');
        $this->loadSelectedRole();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedRole(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->role = '';
        $this->status = '';
        $this->resetPage();
    }

    public function showTab(string $tab): void
    {
        abort_unless(in_array($tab, ['users', 'roles'], true), 404);

        $this->activeTab = $tab;
        $this->showAssignedUsersModal = false;
    }

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
        return Role::query()->orderBy('name')->get();
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

        foreach (config('access-control.groups') as $module => $actions) {
            $permissions = collect($actions)
                ->map(fn (string $action) => $permissionsByName->get("{$module}.{$action}"))
                ->filter();

            if ($permissions->isEmpty()) {
                continue;
            }

            $groups[] = [
                'label' => str($module)->replace('-', ' ')->headline(),
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

        $rolesBefore = $user->roles()->pluck('name')->all();
        $user->roles()->sync($validated['userRoleIds']);
        $user->recordRoleChange($rolesBefore, $user->roles()->pluck('name')->all());
        $user->setActiveRole($validated['userActiveRoleId']);
        $user->syncPermissions(Permission::query()->whereIn('id', $validated['userPermissionIds'])->get());

        Flux::toast(variant: 'success', text: __('users.user_updated'));

        $this->selectUser($user->id);
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
            : null;

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
            $user->delete();
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }

            $this->deleteError = __('users.this_user_is_linked_to_operational_records_and_cannot_be_del');

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

        $this->newRoleName = '';
        $this->selectRole($role->id);
    }

    public function renameRole(): void
    {
        Gate::authorize('access-management.manage-roles');

        $role = Role::query()->findOrFail($this->selectedRoleId);

        abort_if(in_array($role->name, config('access-control.administrator_roles', []), true), 403);

        $validated = $this->validate([
            'selectedRoleName' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')->ignore($role->id)],
        ]);

        $role->update(['name' => $validated['selectedRoleName']]);
    }

    public function deleteRole(): void
    {
        Gate::authorize('access-management.manage-roles');

        $role = Role::query()->findOrFail($this->selectedRoleId);

        abort_if(in_array($role->name, config('access-control.administrator_roles', []), true), 403);

        $role->delete();

        $this->selectedRoleId = Role::query()->orderBy('name')->value('id');
        $this->loadSelectedRole();
    }

    public function selectAllRolePermissions(): void
    {
        Gate::authorize('access-management.manage-permissions');

        $this->rolePermissionIds = Permission::query()->pluck('id')->all();
    }

    public function clearRolePermissions(): void
    {
        Gate::authorize('access-management.manage-permissions');

        if (! $this->selectedRoleIsProtected) {
            $this->rolePermissionIds = [];
        }
    }

    public function saveRolePermissions(): void
    {
        Gate::authorize('access-management.manage-permissions');

        $role = Role::query()->findOrFail($this->selectedRoleId);

        abort_if(in_array($role->name, config('access-control.administrator_roles', []), true), 403);

        $role->syncPermissions(
            Permission::query()->whereIn('id', $this->rolePermissionIds)->get()
        );

        Flux::toast(variant: 'success', text: __('roles.save_permissions'));
        $this->loadSelectedRole();
    }

    protected function resetCreateUserForm(): void
    {
        $this->resetValidation();
        $this->hrUserId = '';
        $this->initialRoleId = '';
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
};
?>

<section
    class="@container/directory mx-auto flex min-h-full w-full max-w-[1800px] flex-1 flex-col gap-[clamp(1rem,1.5vw,1.5rem)]"
    aria-labelledby="access-management-heading"
>
    <header class="rounded-xl border border-gray-200 border-s-4 border-s-primary bg-white px-[clamp(1rem,2vw,1.5rem)] py-[clamp(1rem,1.5vw,1.25rem)] shadow-sm dark:bg-secondary-dark">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h1 id="access-management-heading" class="text-[clamp(1.35rem,2vw,1.75rem)] font-bold leading-tight text-gray-900 dark:text-white">
                {{ __('users.users') }}
            </h1>

            <nav class="flex min-h-11 items-center gap-1 rounded-lg border border-gray-200 bg-gray-50 p-1 dark:border-secondary-medium dark:bg-secondary-dark" aria-label="{{ __('users.users') }}">
                <button type="button" wire:click="showTab('users')" @class([
                    'min-h-9 rounded-md px-4 text-sm font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                    'bg-white text-primary shadow-sm ring-1 ring-gray-200' => $activeTab === 'users',
                    'text-gray-600 hover:bg-white hover:text-gray-900 dark:text-secondary-soft' => $activeTab !== 'users',
                ])>
                    {{ __('users.users') }}
                </button>
                <button type="button" wire:click="showTab('roles')" @class([
                    'min-h-9 rounded-md px-4 text-sm font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                    'bg-white text-primary shadow-sm ring-1 ring-gray-200' => $activeTab === 'roles',
                    'text-gray-600 hover:bg-white hover:text-gray-900 dark:text-secondary-soft' => $activeTab !== 'roles',
                ])>
                    {{ __('roles.title') }}
                </button>
            </nav>
        </div>
    </header>

    @if ($activeTab === 'users')
        <div class="grid min-w-0 gap-[clamp(1rem,1.5vw,1.5rem)] xl:grid-cols-[21rem_minmax(0,1fr)]">
            <section class="flex min-w-0 flex-col overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:bg-secondary-dark">
                <div class="space-y-2.5 border-b border-gray-200 p-3 dark:border-secondary-medium">
                    <div class="flex items-center justify-between gap-2">
                        <label class="sr-only" for="access-user-search">{{ __('users.search_users') }}</label>
                        <input
                            id="access-user-search"
                            type="search"
                            wire:model.live.debounce.350ms="search"
                            placeholder="{{ __('users.search_users') }}"
                            class="min-h-11 w-full min-w-0 rounded-lg border-gray-200 bg-white text-sm text-gray-900 placeholder:text-gray-400 focus:border-primary focus:ring-primary dark:bg-secondary-dark dark:text-white"
                        >
                        @can('create', App\Models\User::class)
                            <flux:button size="sm" variant="primary" icon="user-plus" wire:click="openCreateModal" data-test="create-user-button" />
                        @endcan
                    </div>

                    <div class="grid grid-cols-[repeat(auto-fit,minmax(8rem,1fr))] gap-2">
                        <select wire:model.live="role" aria-label="{{ __('users.all_roles') }}" class="min-h-11 min-w-0 rounded-lg border-gray-200 bg-white text-sm text-gray-700 focus:border-primary focus:ring-primary dark:bg-secondary-dark dark:text-white">
                            <option value="">{{ __('users.all_roles') }}</option>
                            @foreach ($this->roleOptions as $roleOption)
                                <option value="{{ $roleOption->name }}" wire:key="role-filter-{{ $roleOption->id }}">{{ $roleOption->name }}</option>
                            @endforeach
                        </select>
                        <select wire:model.live="status" aria-label="{{ __('common.status') }}" class="min-h-11 min-w-0 rounded-lg border-gray-200 bg-white text-sm text-gray-700 focus:border-primary focus:ring-primary dark:bg-secondary-dark dark:text-white">
                            <option value="">{{ __('users.all_statuses') }}</option>
                            <option value="active">{{ __('common.active') }}</option>
                            <option value="inactive">{{ __('cities.inactive') }}</option>
                        </select>
                    </div>
                </div>

                <div class="max-h-[min(62vh,44rem)] divide-y divide-gray-100 overflow-y-auto overscroll-contain dark:divide-secondary-medium">
                    @forelse ($this->users as $user)
                        <button type="button" wire:click="selectUser({{ $user->id }})" wire:key="access-user-{{ $user->id }}" @class([
                            'group relative flex min-h-[4.5rem] w-full items-center gap-3 px-3 py-2.5 text-start transition focus-visible:z-10 focus-visible:outline-2 focus-visible:outline-inset focus-visible:outline-primary',
                            'bg-gray-50 dark:bg-secondary-medium' => $selectedUserId === $user->id,
                            'bg-white hover:bg-gray-50 dark:bg-secondary-dark dark:hover:bg-secondary-medium' => $selectedUserId !== $user->id,
                        ])>
                            @if ($selectedUserId === $user->id)
                                <span class="absolute inset-y-2 start-0 w-0.5 rounded-full bg-primary" aria-hidden="true"></span>
                            @endif

                            <span class="min-w-0 flex-1">
                                <span @class([
                                    'block truncate text-sm font-bold',
                                    'text-primary' => $selectedUserId === $user->id,
                                    'text-gray-900 dark:text-white' => $selectedUserId !== $user->id,
                                ])>{{ $user->name }}</span>
                                <span class="block truncate text-xs text-gray-500 dark:text-secondary-soft">{{ $user->email }}</span>
                                <span class="mt-1 flex flex-wrap gap-1">
                                    @forelse ($user->roles->take(2) as $userRole)
                                        <span class="rounded-md border border-gray-200 bg-white px-1.5 py-0.5 text-[0.65rem] font-semibold leading-none text-gray-500 dark:border-secondary-medium dark:bg-secondary-dark dark:text-secondary-soft">
                                            {{ $userRole->name }}
                                        </span>
                                    @empty
                                        <span class="rounded-md border border-gray-200 bg-white px-1.5 py-0.5 text-[0.65rem] font-semibold leading-none text-gray-400 dark:border-secondary-medium dark:bg-secondary-dark dark:text-secondary-soft">
                                            {{ __('users.no_role') }}
                                        </span>
                                    @endforelse
                                </span>
                            </span>
                            @can('delete', $user)
                                <span
                                    role="button"
                                    tabindex="0"
                                    wire:click.stop="confirmUserDeletion({{ $user->id }})"
                                    :aria-label="__('common.delete_name', ['name' => $user->name])"
                                    class="grid size-8 shrink-0 place-items-center rounded-md text-gray-400 transition hover:bg-white hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary"
                                >
                                    <flux:icon.trash class="size-4" />
                                </span>
                            @endcan
                        </button>
                    @empty
                        <div class="flex flex-col items-center justify-center px-5 py-12 text-center">
                            <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('users.no_matching_users') }}</p>
                            <p class="mt-1 max-w-64 text-xs leading-5 text-gray-500 dark:text-secondary-soft">{{ __('users.change_the_search_or_role_filter_and_try_again') }}</p>
                        </div>
                    @endforelse
                </div>

                <div class="border-t border-gray-100 px-3 pb-3 dark:border-secondary-medium">
                    <flux:pagination :paginator="$this->users" scroll-to="#access-management-heading" />
                </div>
            </section>

            <section class="flex min-w-0 flex-col rounded-xl border border-gray-200 bg-white shadow-sm dark:bg-secondary-dark">
                @if ($this->selectedUser)
                    @php($selectedUser = $this->selectedUser)
                    <form wire:submit="saveUserAccess">
                        <div class="flex flex-wrap items-center gap-3 border-b border-gray-200 px-[clamp(1rem,2vw,1.5rem)] py-4 dark:border-secondary-medium">
                            <div class="min-w-0 flex-1">
                                <h2 class="truncate text-[clamp(1rem,1.5vw,1.2rem)] font-bold text-gray-900 dark:text-white">{{ $selectedUser->name }}</h2>
                                <p class="truncate text-sm text-gray-500 dark:text-secondary-soft">{{ $selectedUser->email }}</p>
                            </div>
                        </div>

                        <div class="space-y-[clamp(1.25rem,2vw,2rem)] p-[clamp(1rem,2vw,1.5rem)]">
                            <section aria-labelledby="assigned-roles-title">
                                <h3 id="assigned-roles-title" class="mb-3 text-sm font-bold text-gray-900 dark:text-white">{{ __('access.assigned-roles') }}</h3>
                                <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,10rem),1fr))] gap-2">
                                    @foreach ($this->roleOptions as $roleOption)
                                        <label class="relative flex min-h-11 cursor-pointer items-center gap-2.5 rounded-lg border border-gray-200 px-3 py-2 text-sm transition hover:border-gray-300 dark:border-secondary-medium">
                                            <input type="checkbox" wire:model.live="userRoleIds" value="{{ $roleOption->id }}"
                                                @disabled(! Gate::check('update', $selectedUser))
                                                class="peer rounded border-gray-300 text-primary focus:ring-primary">
                                            <span class="min-w-0 truncate font-semibold text-gray-700 peer-checked:text-primary dark:text-secondary-soft">
                                                {{ $roleOption->name }}
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                                @error('userRoleIds') <p class="mt-1.5 text-xs text-primary">{{ $message }}</p> @enderror
                            </section>

                            <section class="border-t border-gray-100 pt-5 dark:border-secondary-medium" aria-labelledby="active-role-title">
                                <label id="active-role-title" for="user-active-role" class="mb-2 block text-sm font-bold text-gray-900 dark:text-white">{{ __('access.active-role') }}</label>
                                <select id="user-active-role" wire:model.live="userActiveRoleId"
                                    @disabled(! Gate::check('update', $selectedUser))
                                    class="min-h-11 w-full rounded-lg border-gray-200 bg-white text-sm text-gray-700 focus:border-primary focus:ring-primary dark:bg-secondary-dark dark:text-white sm:max-w-sm">
                                    <option value="">—</option>
                                    @foreach ($this->roleOptions->whereIn('id', $userRoleIds) as $roleOption)
                                        <option value="{{ $roleOption->id }}">{{ $roleOption->name }}</option>
                                    @endforeach
                                </select>
                                @error('userActiveRoleId') <p class="mt-1.5 text-xs text-primary">{{ $message }}</p> @enderror
                            </section>

                            <section class="border-t border-gray-100 pt-5 dark:border-secondary-medium" aria-labelledby="direct-permissions-title">
                                <h3 id="direct-permissions-title" class="text-sm font-bold text-gray-900 dark:text-white">{{ __('access.direct-permissions') }}</h3>
                                <p class="mt-1 text-xs leading-5 text-gray-500 dark:text-secondary-soft">{{ __('access.direct-permissions-help') }}</p>

                                <div class="mt-4 grid grid-cols-[repeat(auto-fit,minmax(min(100%,18rem),1fr))] gap-3">
                                    @foreach ($this->permissionGroups as $group)
                                        <fieldset class="min-w-0 rounded-lg border border-gray-200 p-3.5 dark:border-secondary-medium">
                                            <legend class="px-1 text-xs font-bold uppercase tracking-wide text-gray-700 dark:text-secondary-soft">{{ $group['label'] }}</legend>
                                            <div class="mt-1 grid grid-cols-[repeat(auto-fit,minmax(8.5rem,1fr))] gap-x-3 gap-y-2">
                                                @foreach ($group['permissions'] as $permission)
                                                    <label class="flex min-h-8 cursor-pointer items-center gap-2 text-sm text-gray-600 dark:text-secondary-soft">
                                                        <input type="checkbox" wire:model.live="userPermissionIds" value="{{ $permission->id }}"
                                                            @disabled(! Gate::check('update', $selectedUser))
                                                            class="rounded border-gray-300 text-primary focus:ring-primary">
                                                        <span>{{ $permission->name }}</span>
                                                    </label>
                                                @endforeach
                                            </div>
                                        </fieldset>
                                    @endforeach
                                </div>
                            </section>
                        </div>

                        @can('update', $selectedUser)
                            <div class="sticky bottom-0 flex justify-end border-t border-gray-200 bg-white/95 px-[clamp(1rem,2vw,1.5rem)] py-3 backdrop-blur-sm dark:border-secondary-medium dark:bg-secondary-dark/95">
                                <flux:button type="submit" variant="primary" data-test="save-user-access-button">{{ __('access.save-access') }}</flux:button>
                            </div>
                        @endcan
                    </form>
                @else
                    <div class="flex min-h-[clamp(22rem,55vh,38rem)] flex-1 items-center justify-center p-6 text-center">
                        <div class="max-w-md">
                            <h2 class="mt-4 text-[clamp(1rem,1.5vw,1.15rem)] font-bold text-gray-900 dark:text-white">{{ __('access.select-employee') }}</h2>
                            <p class="mt-2 text-sm leading-6 text-gray-500 dark:text-secondary-soft">{{ __('access.select-employee-help') }}</p>
                        </div>
                    </div>
                @endif
            </section>
        </div>
    @else
        <div class="grid min-w-0 gap-[clamp(1rem,1.5vw,1.5rem)] xl:grid-cols-[19rem_minmax(0,1fr)]">
            <aside class="min-w-0 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:bg-secondary-dark">
                @can('access-management.manage-roles')
                    <form wire:submit="createRole" class="border-b border-gray-200 p-3 dark:border-secondary-medium" data-test="create-role-form">
                        <label for="new-role-name" class="mb-2 block text-sm font-bold text-gray-900 dark:text-white">{{ __('roles.new_role') }}</label>
                        <div class="grid gap-2">
                            <input id="new-role-name" wire:model="newRoleName"
                                class="min-h-11 min-w-0 rounded-lg border-gray-200 text-sm focus:border-primary focus:ring-primary dark:bg-secondary-dark dark:text-white"
                                placeholder="{{ __('roles.role_name_placeholder') }}">
                            <flux:button type="submit" variant="primary">{{ __('roles.create_role') }}</flux:button>
                        </div>
                        @error('newRoleName') <p class="mt-1.5 text-xs text-primary">{{ $message }}</p> @enderror
                    </form>
                @endcan

                <div data-test="access-role-directory" class="divide-y divide-gray-100 dark:divide-secondary-medium">
                    @foreach ($this->roles as $roleRow)
                        <button type="button" wire:click="selectRole({{ $roleRow->id }})" wire:key="access-role-{{ $roleRow->id }}" @class([
                            'relative flex min-h-12 w-full items-center justify-between gap-3 px-3 py-2.5 text-start transition focus-visible:z-10 focus-visible:outline-2 focus-visible:outline-inset focus-visible:outline-primary',
                            'bg-gray-50 text-primary dark:bg-secondary-medium' => $selectedRoleId === $roleRow->id,
                            'bg-white text-gray-700 hover:bg-gray-50 dark:bg-secondary-dark dark:text-secondary-soft dark:hover:bg-secondary-medium' => $selectedRoleId !== $roleRow->id,
                        ])>
                            @if ($selectedRoleId === $roleRow->id)
                                <span class="absolute inset-y-2 start-0 w-0.5 rounded-full bg-primary" aria-hidden="true"></span>
                            @endif
                            <span class="min-w-0 truncate text-sm font-bold">{{ $roleRow->name }}</span>
                            <span class="inline-flex min-w-6 shrink-0 items-center justify-center rounded-md border border-gray-200 bg-white px-1.5 py-0.5 text-[0.65rem] font-semibold text-gray-500 dark:border-secondary-medium dark:bg-secondary-dark dark:text-secondary-soft">
                                {{ $roleRow->users_count }}
                            </span>
                        </button>
                    @endforeach
                </div>
            </aside>

            <section class="min-w-0 rounded-xl border border-gray-200 bg-white shadow-sm dark:bg-secondary-dark">
                @if ($this->selectedRole)
                    <div class="flex flex-wrap items-start justify-between gap-4 border-b border-gray-200 px-[clamp(1rem,2vw,1.5rem)] py-4 dark:border-secondary-medium">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="truncate text-[clamp(1.1rem,1.6vw,1.35rem)] font-bold text-gray-900 dark:text-white">{{ $this->selectedRole->name }}</h2>
                                @if ($this->selectedRoleIsProtected)
                                    <span class="rounded-md border border-primary px-2 py-1 text-[0.7rem] font-bold text-primary">{{ __('roles.protected_role') }}</span>
                                @endif
                            </div>
                            <button type="button" wire:click="showAssignedUsers" class="group mt-1 inline-flex min-h-8 items-center gap-1.5 rounded-md text-sm font-semibold text-gray-600 underline decoration-gray-300 underline-offset-4 transition hover:text-primary hover:decoration-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary dark:text-secondary-soft" aria-haspopup="dialog">
                                <flux:icon.users class="size-4 shrink-0 text-primary" />
                                <span>{{ trans_choice('roles.users_count', $this->selectedRole->users_count, ['count' => $this->selectedRole->users_count]) }}</span>
                            </button>
                            @if ($this->selectedRoleIsProtected)
                                <p class="mt-1.5 text-xs font-semibold text-primary">{{ __('roles.protected_role_help') }}</p>
                            @endif
                        </div>

                        @can('access-management.manage-roles')
                            @unless ($this->selectedRoleIsProtected)
                                <div class="grid w-full items-end gap-2 sm:w-auto sm:grid-cols-[minmax(13rem,1fr)_auto_auto]">
                                    <div class="min-w-0">
                                        <label class="mb-1.5 block text-xs font-bold text-gray-700 dark:text-secondary-soft" for="selected-role-name">{{ __('roles.rename_role') }}</label>
                                        <input id="selected-role-name" wire:model="selectedRoleName" autocomplete="off" spellcheck="false"
                                            class="min-h-11 w-full min-w-0 rounded-lg border-gray-300 bg-gray-50 px-3 text-sm font-medium text-gray-900 shadow-sm transition hover:border-gray-400 focus:border-primary focus:bg-white focus:ring-primary dark:bg-secondary-dark dark:text-white">
                                    </div>
                                    <flux:button type="button" wire:click="renameRole">{{ __('roles.rename_role') }}</flux:button>
                                    <flux:button type="button" variant="danger" wire:click="deleteRole" wire:confirm="{{ __('roles.delete_role') }}?">{{ __('roles.delete_role') }}</flux:button>
                                </div>
                            @endunless
                        @endcan
                    </div>
                    @error('selectedRoleName') <p class="px-[clamp(1rem,2vw,1.5rem)] pt-2 text-xs text-primary">{{ $message }}</p> @enderror

                    <form wire:submit="saveRolePermissions">
                        <div class="flex flex-wrap items-center justify-between gap-3 px-[clamp(1rem,2vw,1.5rem)] pt-4">
                            <h3 class="text-sm font-bold text-gray-900 dark:text-white">{{ __('roles.permissions') }}</h3>
                            @can('access-management.manage-permissions')
                                <div class="flex flex-wrap gap-2">
                                    <flux:button type="button" size="sm" variant="ghost" wire:click="selectAllRolePermissions">{{ __('roles.select_all') }}</flux:button>
                                    <flux:button type="button" size="sm" variant="ghost" wire:click="clearRolePermissions" :disabled="$this->selectedRoleIsProtected">{{ __('roles.clear_all') }}</flux:button>
                                </div>
                            @endcan
                        </div>

                        <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,18rem),1fr))] gap-3 p-[clamp(1rem,2vw,1.5rem)]">
                            @foreach ($this->permissionGroups as $group)
                                <fieldset class="min-w-0 rounded-lg border border-gray-200 p-3.5 dark:border-secondary-medium">
                                    <legend class="px-1 text-xs font-bold uppercase tracking-wide text-gray-700 dark:text-secondary-soft">{{ $group['label'] }}</legend>
                                    <div class="mt-1 grid grid-cols-[repeat(auto-fit,minmax(8.5rem,1fr))] gap-x-3 gap-y-2">
                                        @foreach ($group['permissions'] as $permission)
                                            <label class="flex min-h-8 items-center gap-2 text-sm text-gray-600 dark:text-secondary-soft">
                                                <input type="checkbox" wire:model="rolePermissionIds" value="{{ $permission->id }}"
                                                    @disabled($this->selectedRoleIsProtected)
                                                    class="rounded border-gray-300 text-primary focus:ring-primary disabled:opacity-70">
                                                <span>{{ $permission->name }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </fieldset>
                            @endforeach
                        </div>

                        @can('access-management.manage-permissions')
                            <div class="sticky bottom-0 flex justify-end border-t border-gray-200 bg-white/95 px-[clamp(1rem,2vw,1.5rem)] py-3 backdrop-blur-sm dark:border-secondary-medium dark:bg-secondary-dark/95">
                                <flux:button type="submit" variant="primary" data-test="save-role-permissions">{{ __('roles.save_permissions') }}</flux:button>
                            </div>
                        @endcan
                    </form>
                @endif
            </section>
        </div>

        <flux:modal wire:model="showAssignedUsersModal" class="w-full max-w-2xl">
            <div class="space-y-4">
                <div>
                    <flux:heading size="lg">{{ __('access.assigned-users', ['count' => $this->selectedRole?->users_count ?? 0]) }}</flux:heading>
                    <flux:subheading>{{ $this->selectedRole?->name }}</flux:subheading>
                </div>

                <div class="max-h-[min(65vh,32rem)] divide-y divide-gray-100 overflow-y-auto dark:divide-secondary-medium">
                    @forelse ($this->selectedRoleUsers as $roleUser)
                        <div class="flex min-w-0 items-center justify-between gap-3 px-1 py-3" wire:key="assigned-role-user-{{ $roleUser->id }}">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-bold text-gray-900 dark:text-white">{{ $roleUser->name }}</p>
                                <p class="truncate text-sm text-gray-500 dark:text-secondary-soft">{{ $roleUser->email }}</p>
                            </div>
                        </div>
                    @empty
                        <p class="py-8 text-center text-sm text-gray-500 dark:text-secondary-soft">{{ __('access.no-assigned-users') }}</p>
                    @endforelse
                </div>

                <div class="flex justify-end">
                    <flux:button wire:click="closeAssignedUsersModal">{{ __('roles.close') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    @endif

    <flux:modal wire:model="showCreateUserModal" class="w-full max-w-xl">
        <form wire:submit="createUser" class="space-y-5">
            <div class="border-b border-gray-200 pb-4 dark:border-secondary-medium">
                <flux:heading size="lg">{{ __('users.add_user') }}</flux:heading>
                <flux:subheading>{{ __('users.give_an_hr_system_account_access_and_choose_its_role_people') }}</flux:subheading>
            </div>

            <flux:select wire:model="hrUserId" :label="__('users.hr_account')" required>
                <flux:select.option value="">{{ __('users.select_an_hr_account') }}</flux:select.option>
                @foreach ($this->hrAccountOptions as $hrAccountOption)
                    <flux:select.option :value="$hrAccountOption->id" :wire:key="'hr-account-option-'.$hrAccountOption->id">
                        {{ $hrAccountOption->email }}@if ($hrAccountOption->employee) · {{ $hrAccountOption->employee->name }} · {{ $hrAccountOption->employee->secondary_name }}@endif
                    </flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="initialRoleId" :label="__('users.role')">
                <flux:select.option value="">{{ __('users.no_role') }}</flux:select.option>
                @foreach ($this->roleOptions as $roleOption)
                    <flux:select.option :value="$roleOption->id" :wire:key="'initial-role-'.$roleOption->id">{{ $roleOption->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="grid grid-cols-2 gap-2 border-t border-gray-200 pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <flux:button type="button" variant="filled" wire:click="closeCreateModal">{{ __('common.cancel') }}</flux:button>
                <flux:button type="submit" variant="primary" data-test="save-user-button">{{ __('users.create_user') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model="showDeleteModal" class="w-full max-w-lg">
        <form wire:submit="deleteUser" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('users.delete_user_2') }}</flux:heading>
                <flux:subheading>{{ __('users.permanently_delete_name_this_action_cannot_be_undone', ['name' => $deletingUserName]) }}</flux:subheading>
            </div>

            @if ($deleteError)
                <div class="rounded-lg border border-primary bg-white p-3 text-sm text-primary" role="alert">
                    <p class="font-semibold">{{ __('users.unable_to_delete_user') }}</p>
                    <p>{{ $deleteError }}</p>
                </div>
            @endif

            <div class="grid grid-cols-2 gap-2 sm:flex sm:justify-end">
                <flux:button type="button" variant="filled" wire:click="closeDeleteModal">{{ __('common.cancel') }}</flux:button>
                <flux:button type="submit" variant="danger" data-test="confirm-delete-user-button">{{ __('users.delete_user') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
