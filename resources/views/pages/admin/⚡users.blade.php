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
    class="admin-directory @container/directory mx-auto flex min-h-full w-full max-w-[1800px] flex-1 flex-col gap-6 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white"
    aria-labelledby="access-management-heading"
>
    <header class="flex flex-wrap items-center justify-between gap-4 border-b border-secondary-soft pb-6 dark:border-secondary-medium">
        <div class="flex items-center gap-2.5">
            <flux:heading id="access-management-heading" size="xl" class="text-3xl! font-bold! leading-none!">
                {{ __('users.users') }}
            </flux:heading>
            <span class="inline-flex min-w-7 items-center justify-center rounded-md bg-primary px-2 py-0.5 text-xs font-bold tabular-nums text-white">
                {{ $activeTab === 'users' ? $this->users->total() : $this->roles->count() }}
            </span>
        </div>

        <nav class="flex min-h-11 items-center gap-1 rounded-lg border border-secondary-soft bg-secondary-soft/40 p-1 dark:border-secondary-medium dark:bg-secondary-medium/40" aria-label="{{ __('users.users') }}">
            <button type="button" wire:click="showTab('users')" @class([
                'min-h-9 rounded-md px-4 text-sm font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                'bg-white text-primary shadow-sm dark:bg-secondary-dark' => $activeTab === 'users',
                'text-secondary-medium hover:text-secondary-dark dark:text-secondary-soft dark:hover:text-white' => $activeTab !== 'users',
            ])>
                {{ __('users.users') }}
            </button>
            <button type="button" wire:click="showTab('roles')" @class([
                'min-h-9 rounded-md px-4 text-sm font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                'bg-white text-primary shadow-sm dark:bg-secondary-dark' => $activeTab === 'roles',
                'text-secondary-medium hover:text-secondary-dark dark:text-secondary-soft dark:hover:text-white' => $activeTab !== 'roles',
            ])>
                {{ __('roles.title') }}
            </button>
        </nav>
    </header>

    @if ($activeTab === 'users')
        <div class="directory-filters grid min-w-0 gap-3 @sm/directory:grid-cols-2 @3xl/directory:grid-cols-3">
            <x-ui.input id="access-user-search" type="search" icon="magnifying-glass" :label="__('users.search_users')" :placeholder="__('users.search_users')" wire:model.live.debounce.350ms="search" clearable />

            <x-ui.header-filter-select id="access-role-filter" :label="__('users.all_roles')" wire:model.live="role" :value="$role">
                <flux:select.option value="">{{ __('users.all_roles') }}</flux:select.option>
                @foreach ($this->roleOptions as $roleOption)
                    <flux:select.option :value="$roleOption->name" :wire:key="'role-filter-'.$roleOption->id">{{ $roleOption->name }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select id="access-status-filter" :label="__('common.status')" wire:model.live="status" :value="$status">
                <flux:select.option value="">{{ __('users.all_statuses') }}</flux:select.option>
                <flux:select.option value="active">{{ __('common.active') }}</flux:select.option>
                <flux:select.option value="inactive">{{ __('cities.inactive') }}</flux:select.option>
            </x-ui.header-filter-select>
        </div>

        <div class="grid min-w-0 gap-6 xl:grid-cols-[21rem_minmax(0,1fr)]">
            <section class="flex min-w-0 flex-col overflow-hidden rounded-xl border border-secondary-soft bg-white shadow-[0_1px_2px_rgba(16,24,40,0.04),0_1px_3px_rgba(16,24,40,0.06)] dark:border-secondary-medium dark:bg-secondary-dark dark:shadow-none">
                <div class="max-h-[min(62vh,44rem)] divide-y divide-secondary-soft overflow-y-auto overscroll-contain dark:divide-secondary-medium">
                    @forelse ($this->users as $user)
                        <button type="button" wire:click="selectUser({{ $user->id }})" wire:key="access-user-{{ $user->id }}" @class([
                            'group relative flex min-h-[4.5rem] w-full items-center gap-3 px-3 py-2.5 text-start transition focus-visible:z-10 focus-visible:outline-2 focus-visible:outline-inset focus-visible:outline-primary',
                            'bg-secondary-soft/60 dark:bg-secondary-medium/60' => $selectedUserId === $user->id,
                            'bg-white hover:bg-secondary-soft/40 dark:bg-secondary-dark dark:hover:bg-secondary-medium/40' => $selectedUserId !== $user->id,
                        ])>
                            @if ($selectedUserId === $user->id)
                                <span class="absolute inset-y-2 start-0 w-0.5 rounded-full bg-primary" aria-hidden="true"></span>
                            @endif

                            <span class="min-w-0 flex-1">
                                <span @class([
                                    'block truncate text-sm font-bold',
                                    'text-primary' => $selectedUserId === $user->id,
                                    'text-secondary-dark dark:text-white' => $selectedUserId !== $user->id,
                                ])>{{ $user->name }}</span>
                                <span class="block truncate text-xs text-secondary-medium dark:text-secondary-soft">{{ $user->email }}</span>
                                <span class="mt-1 flex flex-wrap gap-1">
                                    @forelse ($user->roles->take(2) as $userRole)
                                        <span class="rounded-md border border-secondary-soft bg-white px-1.5 py-0.5 text-[0.65rem] font-semibold leading-none text-secondary-medium dark:border-secondary-medium dark:bg-secondary-dark dark:text-secondary-soft">
                                            {{ $userRole->name }}
                                        </span>
                                    @empty
                                        <span class="rounded-md border border-secondary-soft bg-white px-1.5 py-0.5 text-[0.65rem] font-semibold leading-none text-secondary-light dark:border-secondary-medium dark:bg-secondary-dark dark:text-secondary-soft">
                                            {{ __('users.no_role') }}
                                        </span>
                                    @endforelse
                                </span>
                            </span>
                        </button>
                    @empty
                        <x-ui.empty-state icon="users" :heading="__('users.no_matching_users')" :body="__('users.change_the_search_or_role_filter_and_try_again')" />
                    @endforelse
                </div>

                <div class="border-t border-secondary-soft px-3 pb-3 dark:border-secondary-medium">
                    <flux:pagination
                        :paginator="$this->users"
                        scroll-to="#access-management-heading"
                        class="text-secondary-medium! [&_button]:text-secondary-medium! [&_button:hover]:bg-secondary-soft! dark:text-secondary-soft! dark:[&_button]:text-secondary-soft! dark:[&_button:hover]:bg-secondary-medium!"
                    />
                </div>
            </section>

            <section class="flex min-w-0 flex-col rounded-xl border border-secondary-soft bg-white shadow-[0_1px_2px_rgba(16,24,40,0.04),0_1px_3px_rgba(16,24,40,0.06)] dark:border-secondary-medium dark:bg-secondary-dark dark:shadow-none">
                @if ($this->selectedUser)
                    @php
                        $selectedUser = $this->selectedUser;
                        $checkboxAccentStyle = '--color-accent: var(--color-primary); --color-accent-foreground: var(--color-white);';
                    @endphp
                    <form wire:submit="saveUserAccess">
                        <div class="flex flex-wrap items-center gap-3 border-b border-secondary-soft px-6 py-4 dark:border-secondary-medium">
                            <div class="min-w-0 flex-1">
                                <h2 class="truncate text-lg font-bold text-secondary-dark dark:text-white">{{ $selectedUser->name }}</h2>
                                <p class="truncate text-sm text-secondary-medium dark:text-secondary-soft">{{ $selectedUser->email }}</p>
                            </div>
                        </div>

                        <div class="space-y-8 p-6">
                            <section aria-labelledby="assigned-roles-title">
                                <h3 id="assigned-roles-title" class="mb-3 text-sm font-bold text-secondary-dark dark:text-white">{{ __('access.assigned-roles') }}</h3>
                                <flux:checkbox.group
                                    wire:model.live="userRoleIds"
                                    style="{{ $checkboxAccentStyle }}"
                                    class="grid! grid-cols-[repeat(auto-fit,minmax(min(100%,10rem),1fr))]! gap-x-4! gap-y-2.5! [&>[data-flux-field]]:mb-0!"
                                >
                                    @foreach ($this->roleOptions as $roleOption)
                                        <flux:checkbox
                                            value="{{ $roleOption->id }}"
                                            :label="$roleOption->name"
                                            :disabled="! Gate::check('update', $selectedUser)"
                                            wire:key="user-role-{{ $roleOption->id }}"
                                        />
                                    @endforeach
                                </flux:checkbox.group>
                                @error('userRoleIds') <p class="mt-1.5 text-xs text-status-critical">{{ $message }}</p> @enderror
                            </section>

                            <section class="border-t border-secondary-soft pt-5 dark:border-secondary-medium" aria-labelledby="active-role-title">
                                <label id="active-role-title" for="user-active-role" class="mb-2 block text-sm font-bold text-secondary-dark dark:text-white">{{ __('access.active-role') }}</label>
                                <x-ui.select id="user-active-role" wire:model.live="userActiveRoleId" :disabled="! Gate::check('update', $selectedUser)" class="sm:max-w-sm">
                                    <flux:select.option value="">—</flux:select.option>
                                    @foreach ($this->roleOptions->whereIn('id', $userRoleIds) as $roleOption)
                                        <flux:select.option :value="$roleOption->id">{{ $roleOption->name }}</flux:select.option>
                                    @endforeach
                                </x-ui.select>
                                @error('userActiveRoleId') <p class="mt-1.5 text-xs text-status-critical">{{ $message }}</p> @enderror
                            </section>

                            <section class="border-t border-secondary-soft pt-5 dark:border-secondary-medium" aria-labelledby="direct-permissions-title">
                                <h3 id="direct-permissions-title" class="text-sm font-bold text-secondary-dark dark:text-white">{{ __('access.direct-permissions') }}</h3>
                                <p class="mt-1 text-xs leading-5 text-secondary-medium dark:text-secondary-soft">{{ __('access.direct-permissions-help') }}</p>

                                <div class="mt-4 grid grid-cols-[repeat(auto-fit,minmax(min(100%,18rem),1fr))] gap-3">
                                    @foreach ($this->permissionGroups as $group)
                                        <fieldset class="min-w-0 rounded-lg border border-secondary-soft p-3.5 dark:border-secondary-medium">
                                            <legend class="px-1 text-xs font-bold uppercase tracking-wide text-secondary-medium dark:text-secondary-soft">{{ $group['label'] }}</legend>
                                            <flux:checkbox.group
                                                wire:model.live="userPermissionIds"
                                                style="{{ $checkboxAccentStyle }}"
                                                class="mt-1! grid! grid-cols-[repeat(auto-fit,minmax(8.5rem,1fr))]! gap-x-3! gap-y-2! [&>[data-flux-field]]:mb-0!"
                                            >
                                                @foreach ($group['permissions'] as $permission)
                                                    <flux:checkbox
                                                        value="{{ $permission->id }}"
                                                        :label="(string) str($permission->name)->afterLast('.')->replace('-', ' ')"
                                                        :disabled="! Gate::check('update', $selectedUser)"
                                                        wire:key="user-permission-{{ $permission->id }}"
                                                    />
                                                @endforeach
                                            </flux:checkbox.group>
                                        </fieldset>
                                    @endforeach
                                </div>
                            </section>
                        </div>

                        @can('update', $selectedUser)
                            <div class="sticky bottom-0 flex justify-end border-t border-secondary-soft bg-white/95 px-6 py-3 backdrop-blur-sm dark:border-secondary-medium dark:bg-secondary-dark/95">
                                <x-ui.button type="submit" tone="primary" data-test="save-user-access-button">{{ __('access.save-access') }}</x-ui.button>
                            </div>
                        @endcan
                    </form>
                @else
                    <x-ui.empty-state icon="user" :heading="__('access.select-employee')" :body="__('access.select-employee-help')" class="min-h-[clamp(22rem,55vh,38rem)] flex-1" />
                @endif
            </section>
        </div>
    @else
        <div class="grid min-w-0 gap-6 xl:grid-cols-[19rem_minmax(0,1fr)]">
            <aside class="min-w-0 overflow-hidden rounded-xl border border-secondary-soft bg-white shadow-[0_1px_2px_rgba(16,24,40,0.04),0_1px_3px_rgba(16,24,40,0.06)] dark:border-secondary-medium dark:bg-secondary-dark dark:shadow-none">
                @can('access-management.manage-roles')
                    <form wire:submit="createRole" class="border-b border-secondary-soft p-3 dark:border-secondary-medium" data-test="create-role-form">
                        <label for="new-role-name" class="mb-2 block text-sm font-bold text-secondary-dark dark:text-white">{{ __('roles.new_role') }}</label>
                        <div class="grid gap-2">
                            <x-ui.input id="new-role-name" wire:model="newRoleName" :placeholder="__('roles.role_name_placeholder')" />
                            <x-ui.button type="submit" tone="primary">{{ __('roles.create_role') }}</x-ui.button>
                        </div>
                        @error('newRoleName') <p class="mt-1.5 text-xs text-status-critical">{{ $message }}</p> @enderror
                    </form>
                @endcan

                <div data-test="access-role-directory" class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                    @foreach ($this->roles as $roleRow)
                        <button type="button" wire:click="selectRole({{ $roleRow->id }})" wire:key="access-role-{{ $roleRow->id }}" @class([
                            'relative flex min-h-12 w-full items-center justify-between gap-3 px-3 py-2.5 text-start transition focus-visible:z-10 focus-visible:outline-2 focus-visible:outline-inset focus-visible:outline-primary',
                            'bg-secondary-soft/60 text-primary dark:bg-secondary-medium/60' => $selectedRoleId === $roleRow->id,
                            'bg-white text-secondary-medium hover:bg-secondary-soft/40 dark:bg-secondary-dark dark:text-secondary-soft dark:hover:bg-secondary-medium/40' => $selectedRoleId !== $roleRow->id,
                        ])>
                            @if ($selectedRoleId === $roleRow->id)
                                <span class="absolute inset-y-2 start-0 w-0.5 rounded-full bg-primary" aria-hidden="true"></span>
                            @endif
                            <span class="min-w-0 truncate text-sm font-bold">{{ $roleRow->name }}</span>
                            <span class="inline-flex min-w-6 shrink-0 items-center justify-center rounded-md border border-secondary-soft bg-white px-1.5 py-0.5 text-[0.65rem] font-semibold text-secondary-medium dark:border-secondary-medium dark:bg-secondary-dark dark:text-secondary-soft">
                                {{ $roleRow->users_count }}
                            </span>
                        </button>
                    @endforeach
                </div>
            </aside>

            <section class="min-w-0 rounded-xl border border-secondary-soft bg-white shadow-[0_1px_2px_rgba(16,24,40,0.04),0_1px_3px_rgba(16,24,40,0.06)] dark:border-secondary-medium dark:bg-secondary-dark dark:shadow-none">
                @if ($this->selectedRole)
                    <div class="flex flex-wrap items-start justify-between gap-4 border-b border-secondary-soft px-6 py-4 dark:border-secondary-medium">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="truncate text-xl font-bold text-secondary-dark dark:text-white">{{ $this->selectedRole->name }}</h2>
                                @if ($this->selectedRoleIsProtected)
                                    <span class="rounded-md border border-primary px-2 py-1 text-[0.7rem] font-bold text-primary">{{ __('roles.protected_role') }}</span>
                                @endif
                            </div>
                            <button type="button" wire:click="showAssignedUsers" class="group mt-1 inline-flex min-h-8 items-center gap-1.5 rounded-md text-sm font-semibold text-secondary-medium underline decoration-secondary-light underline-offset-4 transition hover:text-primary hover:decoration-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary dark:text-secondary-soft" aria-haspopup="dialog">
                                <flux:icon.users class="size-4 shrink-0 text-primary" />
                                <span>{{ trans_choice('roles.users_count', $this->selectedRole->users_count, ['count' => $this->selectedRole->users_count]) }}</span>
                            </button>
                            @if ($this->selectedRoleIsProtected)
                                <p class="mt-1.5 text-xs font-semibold text-primary">{{ __('roles.protected_role_help') }}</p>
                            @endif
                        </div>

                        @can('access-management.manage-roles')
                            @unless ($this->selectedRoleIsProtected)
                                <div class="grid w-full items-end gap-2 sm:w-auto sm:grid-cols-[minmax(13rem,1fr)_auto]">
                                    <x-ui.input id="selected-role-name" wire:model="selectedRoleName" autocomplete="off" spellcheck="false" :label="__('roles.rename_role')" />
                                    <x-ui.button type="button" wire:click="renameRole">{{ __('roles.rename_role') }}</x-ui.button>
                                </div>
                            @endunless
                        @endcan
                    </div>
                    @error('selectedRoleName') <p class="px-6 pt-2 text-xs text-status-critical">{{ $message }}</p> @enderror

                    <form wire:submit="saveRolePermissions">
                        <div class="flex flex-wrap items-center justify-between gap-3 px-6 pt-4">
                            <h3 class="text-sm font-bold text-secondary-dark dark:text-white">{{ __('roles.permissions') }}</h3>
                            @can('access-management.manage-permissions')
                                <div class="flex flex-wrap gap-2">
                                    <x-ui.button type="button" tone="ghost" wire:click="selectAllRolePermissions">{{ __('roles.select_all') }}</x-ui.button>
                                    <x-ui.button type="button" tone="ghost" wire:click="clearRolePermissions" :disabled="$this->selectedRoleIsProtected">{{ __('roles.clear_all') }}</x-ui.button>
                                </div>
                            @endcan
                        </div>

                        <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,18rem),1fr))] gap-3 p-6">
                            @foreach ($this->permissionGroups as $group)
                                <fieldset class="min-w-0 rounded-lg border border-secondary-soft p-3.5 dark:border-secondary-medium">
                                    <legend class="px-1 text-xs font-bold uppercase tracking-wide text-secondary-medium dark:text-secondary-soft">{{ $group['label'] }}</legend>
                                    <flux:checkbox.group
                                        wire:model="rolePermissionIds"
                                        style="--color-accent: var(--color-primary); --color-accent-foreground: var(--color-white);"
                                        class="mt-1! grid! grid-cols-[repeat(auto-fit,minmax(8.5rem,1fr))]! gap-x-3! gap-y-2! [&>[data-flux-field]]:mb-0!"
                                    >
                                        @foreach ($group['permissions'] as $permission)
                                            <flux:checkbox
                                                value="{{ $permission->id }}"
                                                :label="(string) str($permission->name)->afterLast('.')->replace('-', ' ')"
                                                :disabled="$this->selectedRoleIsProtected"
                                                wire:key="role-permission-{{ $permission->id }}"
                                            />
                                        @endforeach
                                    </flux:checkbox.group>
                                </fieldset>
                            @endforeach
                        </div>

                        @can('access-management.manage-permissions')
                            <div class="sticky bottom-0 flex justify-end border-t border-secondary-soft bg-white/95 px-6 py-3 backdrop-blur-sm dark:border-secondary-medium dark:bg-secondary-dark/95">
                                <x-ui.button type="submit" tone="primary" data-test="save-role-permissions">{{ __('roles.save_permissions') }}</x-ui.button>
                            </div>
                        @endcan
                    </form>
                @endif
            </section>
        </div>

        <flux:modal wire:model="showAssignedUsersModal" class="w-full max-w-2xl border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
            <div class="space-y-4">
                <div>
                    <flux:heading size="lg">{{ __('access.assigned-users', ['count' => $this->selectedRole?->users_count ?? 0]) }}</flux:heading>
                    <flux:subheading>{{ $this->selectedRole?->name }}</flux:subheading>
                </div>

                <div class="max-h-[min(65vh,32rem)] divide-y divide-secondary-soft overflow-y-auto dark:divide-secondary-medium">
                    @forelse ($this->selectedRoleUsers as $roleUser)
                        <div class="flex min-w-0 items-center justify-between gap-3 px-1 py-3" wire:key="assigned-role-user-{{ $roleUser->id }}">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-bold text-secondary-dark dark:text-white">{{ $roleUser->name }}</p>
                                <p class="truncate text-sm text-secondary-medium dark:text-secondary-soft">{{ $roleUser->email }}</p>
                            </div>
                        </div>
                    @empty
                        <p class="py-8 text-center text-sm text-secondary-medium dark:text-secondary-soft">{{ __('access.no-assigned-users') }}</p>
                    @endforelse
                </div>

                <div class="flex justify-end">
                    <x-ui.button wire:click="closeAssignedUsersModal">{{ __('roles.close') }}</x-ui.button>
                </div>
            </div>
        </flux:modal>
    @endif

    <flux:modal wire:model="showCreateUserModal" class="w-full max-w-xl border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        <form wire:submit="createUser" class="space-y-5">
            <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                <flux:heading size="lg">{{ __('users.add_user') }}</flux:heading>
                <flux:subheading>{{ __('users.give_an_hr_system_account_access_and_choose_its_role_people') }}</flux:subheading>
            </div>

            <x-ui.select wire:model="hrUserId" :label="__('users.hr_account')" required>
                <flux:select.option value="">{{ __('users.select_an_hr_account') }}</flux:select.option>
                @foreach ($this->hrAccountOptions as $hrAccountOption)
                    <flux:select.option :value="$hrAccountOption->id" :wire:key="'hr-account-option-'.$hrAccountOption->id">
                        {{ $hrAccountOption->email }}@if ($hrAccountOption->employee) · {{ $hrAccountOption->employee->name }} · {{ $hrAccountOption->employee->secondary_name }}@endif
                    </flux:select.option>
                @endforeach
            </x-ui.select>

            <x-ui.select wire:model="initialRoleId" :label="__('users.role')">
                <flux:select.option value="">{{ __('users.no_role') }}</flux:select.option>
                @foreach ($this->roleOptions as $roleOption)
                    <flux:select.option :value="$roleOption->id" :wire:key="'initial-role-'.$roleOption->id">{{ $roleOption->name }}</flux:select.option>
                @endforeach
            </x-ui.select>

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" wire:click="closeCreateModal">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" data-test="save-user-button">{{ __('users.create_user') }}</x-ui.button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model="showDeleteModal" class="w-full max-w-lg border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        <form wire:submit="deleteUser" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('users.delete_user_2') }}</flux:heading>
                <flux:subheading>{{ __('users.permanently_delete_name_this_action_cannot_be_undone', ['name' => $deletingUserName]) }}</flux:subheading>
            </div>

            @if ($deleteError)
                <div class="rounded-lg border border-primary bg-white p-3 text-sm text-status-critical" role="alert">
                    <p class="font-semibold">{{ __('users.unable_to_delete_user') }}</p>
                    <p>{{ $deleteError }}</p>
                </div>
            @endif

            <div class="grid grid-cols-2 gap-2 sm:flex sm:justify-end">
                <x-ui.button type="button" wire:click="closeDeleteModal">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" data-test="confirm-delete-user-button">{{ __('users.delete_user') }}</x-ui.button>
            </div>
        </form>
    </flux:modal>
</section>
