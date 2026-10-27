<?php

use App\Livewire\Concerns\Admin\ManagesRoles;
use App\Livewire\Concerns\Admin\ManagesUserAccess;
use App\Models\Role;
use App\Models\Supervision;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::desk'), Title('titles.user_directory')] class extends Component {
    use ManagesRoles;
    use ManagesUserAccess;
    use WithPagination;

    #[Url(as: 'tab', except: 'users')]
    public string $activeTab = 'users';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $role = '';

    #[Url(except: '')]
    public string $status = '';

    #[Locked]
    public ?int $selectedRoleId = null;

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
        abort_unless(in_array($tab, ['users', 'roles', 'supervision'], true), 404);

        if ($tab === 'supervision') {
            Gate::authorize('manage', Supervision::class);
        }

        $this->activeTab = $tab;
        $this->showAssignedUsersModal = false;
    }
};
?>

<x-desk.register class="admin-directory @container/directory" heading-id="access-management-heading" :title="__('users.users')" :count="$activeTab === 'supervision' ? null : ($activeTab === 'users' ? $this->users->total() : $this->roles->count())">
    @if ($activeTab === 'users')
        <x-slot:search>
            <x-desk.register-search id="access-user-search" :label="__('users.search_users')" :placeholder="__('users.search_users')" wire:model.live.debounce.350ms="search" />
        </x-slot:search>
        <x-slot:filters>
            <x-ui.header-filter-select id="access-role-filter" :label="__('users.all_roles')" wire:model.live="role" :value="$role">
                <option value="">{{ __('users.all_roles') }}</option>
                @foreach ($this->roleOptions as $roleOption)
                    <option value="{{ $roleOption->name }}">{{ $roleOption->name }}</option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select id="access-status-filter" :label="__('common.status')" wire:model.live="status" :value="$status">
                <option value="">{{ __('users.all_statuses') }}</option>
                <option value="active">{{ __('common.active') }}</option>
                <option value="inactive">{{ __('cities.inactive') }}</option>
            </x-ui.header-filter-select>
        </x-slot:filters>
    @endif

    <x-slot:actions>
        <nav class="flex min-h-11 items-center gap-1 rounded-lg border border-secondary-soft bg-secondary-soft/40 p-1 dark:border-line dark:bg-surface-2" aria-label="{{ __('users.users') }}">
            <button type="button" wire:click="showTab('users')" @class([
                'min-h-9 rounded-md px-4 text-sm font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium',
                'bg-white text-primary dark:text-brand-text shadow-sm dark:bg-surface-3 dark:shadow-raised' => $activeTab === 'users',
                'text-secondary-medium hover:text-secondary-dark dark:text-ink-muted dark:hover:text-ink' => $activeTab !== 'users',
            ])>
                {{ __('users.users') }}
            </button>
            <button type="button" wire:click="showTab('roles')" @class([
                'min-h-9 rounded-md px-4 text-sm font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium',
                'bg-white text-primary dark:text-brand-text shadow-sm dark:bg-surface-3 dark:shadow-raised' => $activeTab === 'roles',
                'text-secondary-medium hover:text-secondary-dark dark:text-ink-muted dark:hover:text-ink' => $activeTab !== 'roles',
            ])>
                {{ __('roles.title') }}
            </button>
            @can('manage', Supervision::class)
                <button type="button" wire:click="showTab('supervision')" data-test="supervision-tab" @class([
                    'min-h-9 rounded-md px-4 text-sm font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium',
                    'bg-white text-primary dark:text-brand-text shadow-sm dark:bg-surface-3 dark:shadow-raised' => $activeTab === 'supervision',
                    'text-secondary-medium hover:text-secondary-dark dark:text-ink-muted dark:hover:text-ink' => $activeTab !== 'supervision',
                ])>
                    {{ __('users.supervision_tab') }}
                </button>
            @endcan
        </nav>
    </x-slot:actions>

    @if ($activeTab === 'users')
        @include('pages.admin._users-tab')
    @elseif ($activeTab === 'supervision')
        <livewire:admin.supervisions />
    @else
        @include('pages.admin._roles-tab')
    @endif

    @include('pages.admin._users-modals')
</x-desk.register>
