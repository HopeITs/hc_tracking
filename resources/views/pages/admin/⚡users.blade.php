<?php

use App\Livewire\Concerns\Admin\ManagesRoles;
use App\Livewire\Concerns\Admin\ManagesUserAccess;
use App\Models\Role;
use App\Models\Supervision;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('titles.user_directory')] class extends Component {
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

<section
    class="admin-directory @container/directory mx-auto flex min-h-full w-full max-w-[1800px] flex-1 flex-col gap-6 bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink"
    aria-labelledby="access-management-heading"
>
    <header class="flex flex-wrap items-center justify-between gap-4 border-b border-secondary-soft pb-6 dark:border-line">
        <div class="flex items-center gap-2.5">
            <flux:heading id="access-management-heading" size="xl" class="text-3xl! font-bold! leading-none!">
                {{ __('users.users') }}
            </flux:heading>
            @if ($activeTab !== 'supervision')
            <span class="inline-flex min-w-7 items-center justify-center rounded-md bg-primary dark:bg-white/10 dark:text-ink px-2 py-0.5 text-xs font-bold tabular-nums text-white">
                {{ $activeTab === 'users' ? $this->users->total() : $this->roles->count() }}
            </span>
            @endif
        </div>

        <nav class="flex min-h-11 items-center gap-1 rounded-lg border border-secondary-soft bg-secondary-soft/40 p-1 dark:border-line dark:bg-surface-2" aria-label="{{ __('users.users') }}">
            <button type="button" wire:click="showTab('users')" @class([
                'min-h-9 rounded-md px-4 text-sm font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                'bg-white text-primary dark:text-brand-text shadow-sm dark:bg-surface-3 dark:shadow-raised' => $activeTab === 'users',
                'text-secondary-medium hover:text-secondary-dark dark:text-ink-muted dark:hover:text-ink' => $activeTab !== 'users',
            ])>
                {{ __('users.users') }}
            </button>
            <button type="button" wire:click="showTab('roles')" @class([
                'min-h-9 rounded-md px-4 text-sm font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                'bg-white text-primary dark:text-brand-text shadow-sm dark:bg-surface-3 dark:shadow-raised' => $activeTab === 'roles',
                'text-secondary-medium hover:text-secondary-dark dark:text-ink-muted dark:hover:text-ink' => $activeTab !== 'roles',
            ])>
                {{ __('roles.title') }}
            </button>
            @can('manage', Supervision::class)
                <button type="button" wire:click="showTab('supervision')" data-test="supervision-tab" @class([
                    'min-h-9 rounded-md px-4 text-sm font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                    'bg-white text-primary dark:text-brand-text shadow-sm dark:bg-surface-3 dark:shadow-raised' => $activeTab === 'supervision',
                    'text-secondary-medium hover:text-secondary-dark dark:text-ink-muted dark:hover:text-ink' => $activeTab !== 'supervision',
                ])>
                    {{ __('users.supervision_tab') }}
                </button>
            @endcan
        </nav>
    </header>

    @if ($activeTab === 'users')
        @include('pages.admin._users-tab')
    @elseif ($activeTab === 'supervision')
        <livewire:admin.supervisions />
    @else
        @include('pages.admin._roles-tab')
    @endif

    @include('pages.admin._users-modals')
</section>
