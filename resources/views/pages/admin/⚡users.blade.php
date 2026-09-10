<?php

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\UserRole;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('User directory')] class extends Component {
    use PasswordValidationRules;
    use ProfileValidationRules;
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $role = '';

    public bool $showUserModal = false;
    public bool $showDeleteModal = false;
    public ?int $editingUserId = null;
    public ?int $deletingUserId = null;
    public string $name = '';
    public string $email = '';
    public string $selectedRole = UserRole::Officer->value;
    public string $password = '';
    public string $password_confirmation = '';
    public string $deletingUserName = '';
    public ?string $deleteError = null;

    public function mount(): void
    {
        Gate::authorize('viewAny', User::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedRole(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        Gate::authorize('viewAny', User::class);

        $this->search = '';
        $this->role = '';
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, User>
     */
    #[Computed]
    public function users(): LengthAwarePaginator
    {
        Gate::authorize('viewAny', User::class);

        return User::query()
            ->select(['id', 'name', 'email', 'role', 'email_verified_at', 'created_at'])
            ->when($this->search !== '', function ($query): void {
                $query->where(function ($query): void {
                    $query
                        ->where('name', 'like', '%'.$this->search.'%')
                        ->orWhere('email', 'like', '%'.$this->search.'%');
                });
            })
            ->when($this->role !== '', fn ($query) => $query->where('role', $this->role))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15);
    }

    /**
     * @return array<int, UserRole>
     */
    #[Computed]
    public function roleOptions(): array
    {
        return UserRole::cases();
    }

    public function openCreateModal(): void
    {
        Gate::authorize('create', User::class);

        $this->resetUserForm();
        $this->showUserModal = true;
    }

    public function openEditModal(int $userId): void
    {
        $user = User::query()->findOrFail($userId);

        Gate::authorize('update', $user);

        $this->resetValidation();
        $this->editingUserId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->selectedRole = $user->role->value;
        $this->password = '';
        $this->password_confirmation = '';
        $this->showUserModal = true;
    }

    public function saveUser(): void
    {
        $user = $this->editingUserId === null
            ? new User
            : User::query()->findOrFail($this->editingUserId);

        Gate::authorize($user->exists ? 'update' : 'create', $user->exists ? $user : User::class);

        $validated = $this->validate([
            ...$this->profileRules($user->exists ? $user->id : null),
            'selectedRole' => ['required', Rule::enum(UserRole::class)],
            'password' => $user->exists
                ? ['nullable', 'string', Password::default(), 'confirmed']
                : $this->passwordRules(),
        ], [
            'selectedRole.required' => __('Select a role.'),
            'selectedRole.enum' => __('Select a valid role.'),
        ]);

        $attributes = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => UserRole::from($validated['selectedRole']),
        ];

        if (! $user->exists) {
            $attributes['email_verified_at'] = now();
        }

        if ($this->password !== '') {
            $attributes['password'] = $validated['password'];
        }

        $user->forceFill($attributes)->save();

        $this->showUserModal = false;
        $this->resetUserForm();

        Flux::toast(
            variant: 'success',
            text: $user->wasRecentlyCreated ? __('User created.') : __('User updated.'),
        );
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

            $this->deleteError = __('This user is linked to operational records and cannot be deleted. Reassign those records first.');

            return;
        }

        $this->showDeleteModal = false;
        $this->deletingUserId = null;
        $this->deletingUserName = '';
        $this->deleteError = null;

        Flux::toast(variant: 'success', text: __('User deleted.'));
    }

    public function closeUserModal(): void
    {
        $this->showUserModal = false;
        $this->resetUserForm();
    }

    public function closeDeleteModal(): void
    {
        $this->showDeleteModal = false;
        $this->deletingUserId = null;
        $this->deletingUserName = '';
        $this->deleteError = null;
    }

    protected function resetUserForm(): void
    {
        $this->resetValidation();
        $this->editingUserId = null;
        $this->name = '';
        $this->email = '';
        $this->selectedRole = UserRole::Officer->value;
        $this->password = '';
        $this->password_confirmation = '';
    }
};
?>

@php
    $roleBadgeClasses = fn (UserRole $role): string => match ($role) {
        UserRole::Admin => 'border-primary bg-primary text-white',
        UserRole::Manager => 'border-secondary-dark bg-secondary-dark text-white dark:border-secondary-soft',
        UserRole::Meal => 'border-secondary-medium bg-secondary-medium text-white',
        UserRole::Officer => 'border-secondary-light bg-secondary-light text-white',
    };
    $controlClasses = 'border-secondary-light! bg-white! text-secondary-dark! placeholder:text-secondary-light! dark:border-secondary-light! dark:bg-secondary-medium! dark:text-white!';
    $primaryButtonClasses = 'border-primary! bg-primary! text-white! hover:border-primary! hover:bg-primary!';
    $secondaryButtonClasses = 'border-secondary-light! bg-white! text-secondary-dark! hover:bg-secondary-soft! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white! dark:hover:bg-secondary-medium!';
    $editButtonClasses = 'border-0! bg-transparent! text-secondary-dark! hover:bg-secondary-soft! dark:text-white! dark:hover:bg-secondary-medium!';
    $deleteButtonClasses = 'border-0! bg-transparent! text-primary! hover:bg-primary! hover:text-white!';
@endphp

<section
    class="admin-directory @container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white [&_[data-flux-cell]]:border-secondary-soft! [&_[data-flux-cell]]:text-secondary-medium! [&_[data-flux-column]]:border-secondary-soft! [&_[data-flux-column]]:text-secondary-dark! [&_[data-flux-heading]]:text-secondary-dark! [&_[data-flux-label]]:text-secondary-dark! [&_[data-flux-subheading]]:text-secondary-medium! dark:[&_[data-flux-cell]]:border-secondary-medium! dark:[&_[data-flux-cell]]:text-secondary-soft! dark:[&_[data-flux-column]]:border-secondary-medium! dark:[&_[data-flux-column]]:text-white! dark:[&_[data-flux-heading]]:text-white! dark:[&_[data-flux-label]]:text-white! dark:[&_[data-flux-subheading]]:text-secondary-soft!"
    aria-labelledby="user-directory-heading"
>
    <div class="flex flex-col gap-3 border-b border-secondary-soft pb-4 dark:border-secondary-medium @4xl/directory:flex-row @4xl/directory:items-end">
        <div class="min-w-40 @4xl/directory:me-auto">
            <div class="flex items-center gap-2">
                <flux:heading id="user-directory-heading" size="lg">{{ __('Users') }}</flux:heading>
                <span class="inline-flex min-w-7 items-center justify-center rounded-md border border-secondary-light px-2 py-0.5 text-xs font-semibold text-secondary-medium dark:border-secondary-light dark:text-secondary-soft">
                    {{ $this->users->total() }}
                </span>
            </div>
            <p class="text-sm text-secondary-medium dark:text-secondary-soft">{{ __('Manage staff accounts and access roles.') }}</p>
        </div>

        <div class="grid w-full gap-2 @sm/directory:grid-cols-[minmax(12rem,1fr)_minmax(9rem,11rem)_auto] @4xl/directory:max-w-[46rem]">
            <flux:input
                type="search"
                icon="magnifying-glass"
                :label="__('Search users')"
                :placeholder="__('Name or email')"
                :class="$controlClasses"
                wire:model.live.debounce.300ms="search"
                clearable
            />

            <flux:select :label="__('Role')" :class="$controlClasses" wire:model.live="role">
                <flux:select.option value="" class="text-secondary-dark! hover:bg-secondary-soft! dark:bg-secondary-dark! dark:text-white! dark:hover:bg-secondary-medium!">
                    {{ __('All roles') }}
                </flux:select.option>
                @foreach ($this->roleOptions as $roleOption)
                    <flux:select.option
                        :value="$roleOption->value"
                        :wire:key="'role-filter-'.$roleOption->value"
                        class="text-secondary-dark! hover:bg-secondary-soft! dark:bg-secondary-dark! dark:text-white! dark:hover:bg-secondary-medium!"
                    >
                        {{ $roleOption->value }}
                    </flux:select.option>
                @endforeach
            </flux:select>

            <flux:button
                variant="primary"
                icon="user-plus"
                :class="$primaryButtonClasses.' min-h-11 self-end touch-manipulation @sm/directory:min-h-10'"
                wire:click="openCreateModal"
                data-test="create-user-button"
            >
                {{ __('Add user') }}
            </flux:button>
        </div>
    </div>

    <div class="overflow-hidden rounded-lg border border-secondary-light bg-white dark:border-secondary-medium dark:bg-secondary-dark">
        <div class="flex min-h-10 flex-wrap items-center justify-between gap-2 border-b border-secondary-soft bg-white px-3.5 py-2 dark:border-secondary-medium dark:bg-secondary-dark">
            <p class="text-xs font-semibold text-secondary-medium dark:text-secondary-soft">
                {{ trans_choice('{1} :count matching user|[2,*] :count matching users', $this->users->total(), ['count' => $this->users->total()]) }}
            </p>

            <div class="flex items-center gap-2">
                <span wire:loading.delay.flex wire:target="search,role,clearFilters" class="hidden items-center gap-1.5 text-xs text-secondary-medium dark:text-secondary-soft">
                    <flux:icon.arrow-path class="size-3.5 animate-spin" />
                    {{ __('Updating') }}
                </span>

                @if ($search !== '' || $role !== '')
                    <flux:button
                        size="sm"
                        variant="ghost"
                        class="text-secondary-medium! hover:bg-secondary-soft! hover:text-secondary-dark! dark:text-secondary-soft! dark:hover:bg-secondary-medium! dark:hover:text-white!"
                        wire:click="clearFilters"
                    >
                        {{ __('Reset filters') }}
                    </flux:button>
                @endif
            </div>
        </div>

        @if ($this->users->isEmpty())
            <div class="flex min-h-56 flex-col items-center justify-center gap-3 px-5 py-10 text-center">
                <div class="flex size-11 items-center justify-center rounded-lg border border-secondary-light bg-white text-secondary-medium dark:bg-secondary-dark dark:text-secondary-soft">
                    <flux:icon.users class="size-5" />
                </div>
                <div class="space-y-1">
                    <flux:heading size="base">{{ __('No matching users') }}</flux:heading>
                    <p class="text-sm text-secondary-medium dark:text-secondary-soft">{{ __('Change the search or role filter and try again.') }}</p>
                </div>
                <flux:button size="sm" :class="$secondaryButtonClasses" wire:click="clearFilters">
                    {{ __('Reset filters') }}
                </flux:button>
            </div>
        @else
            <div class="divide-y divide-secondary-soft dark:divide-secondary-medium @4xl/directory:hidden">
                @foreach ($this->users as $user)
                    <article class="flex flex-col gap-3 p-3.5" wire:key="mobile-user-{{ $user->id }}">
                        <div class="flex min-w-0 items-start justify-between gap-3">
                            <div class="flex min-w-0 items-center gap-3">
                                <flux:avatar class="bg-secondary-soft! text-secondary-dark! dark:bg-secondary-medium! dark:text-white!" :initials="$user->initials()" />
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold text-secondary-dark dark:text-white">{{ $user->name }}</p>
                                    <p class="truncate text-xs text-secondary-medium dark:text-secondary-soft">{{ $user->email }}</p>
                                </div>
                            </div>

                            <span class="inline-flex shrink-0 rounded-md border px-2 py-1 text-xs font-semibold {{ $roleBadgeClasses($user->role) }}">
                                {{ $user->role->value }}
                            </span>
                        </div>

                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-secondary-medium dark:text-secondary-soft">
                            <span class="inline-flex items-center gap-1.5">
                                <span class="size-1.5 rounded-full {{ $user->email_verified_at ? 'bg-primary' : 'bg-secondary-light' }}"></span>
                                {{ $user->email_verified_at ? __('Verified') : __('Pending verification') }}
                            </span>
                            <span>{{ __('Joined :date', ['date' => $user->created_at?->format('M j, Y')]) }}</span>
                        </div>

                        <div class="flex min-h-11 items-center justify-end gap-2 border-t border-secondary-soft pt-2.5 dark:border-secondary-medium">
                            @canany(['update', 'delete'], $user)
                                @can('update', $user)
                                    <flux:button
                                        size="sm"
                                        icon="pencil-square"
                                        :class="$editButtonClasses.' size-11! touch-manipulation'"
                                        wire:click="openEditModal({{ $user->id }})"
                                        :aria-label="__('Edit :name', ['name' => $user->name])"
                                    />
                                @endcan

                                @can('delete', $user)
                                    <flux:button
                                        size="sm"
                                        icon="trash"
                                        :class="$deleteButtonClasses.' size-11! touch-manipulation'"
                                        wire:click="confirmUserDeletion({{ $user->id }})"
                                        :aria-label="__('Delete :name', ['name' => $user->name])"
                                    />
                                @endcan
                            @else
                                <span class="rounded-md border border-secondary-light bg-secondary-soft px-2 py-1 text-xs font-medium text-secondary-dark">
                                    {{ __('Current account') }}
                                </span>
                            @endcanany
                        </div>
                    </article>
                @endforeach
            </div>

            <div class="hidden @4xl/directory:block">
                <flux:table class="table-fixed [&_[data-flux-cell]]:px-3.5! [&_[data-flux-cell]]:py-2! [&_[data-flux-column]]:px-3.5! [&_[data-flux-column]]:py-2.5! [&_tbody_tr]:transition-colors [&_tbody_tr]:hover:bg-secondary-soft dark:[&_tbody_tr]:hover:bg-secondary-medium">
                    <flux:table.columns class="bg-secondary-soft text-xs font-semibold dark:bg-secondary-medium">
                        <flux:table.column class="w-[40%]">{{ __('User') }}</flux:table.column>
                        <flux:table.column class="w-[15%]" align="center">{{ __('Role') }}</flux:table.column>
                        <flux:table.column class="w-[15%]" align="center">{{ __('Access') }}</flux:table.column>
                        <flux:table.column class="w-[18%]" align="center">{{ __('Joined') }}</flux:table.column>
                        <flux:table.column class="w-[12%]" align="center">{{ __('Actions') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->users as $user)
                            <flux:table.row :key="$user->id">
                                <flux:table.cell variant="strong" class="text-secondary-dark! dark:text-white!">
                                    <div class="flex min-w-0 items-center gap-2.5">
                                        <flux:avatar class="bg-secondary-soft! text-secondary-dark! dark:bg-secondary-medium! dark:text-white!" size="sm" :initials="$user->initials()" />
                                        <div class="min-w-0">
                                            <p class="truncate font-semibold">{{ $user->name }}</p>
                                            <p class="truncate text-xs font-normal text-secondary-medium dark:text-secondary-soft">{{ $user->email }}</p>
                                        </div>
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell align="center">
                                    <span class="inline-flex rounded-md border px-2 py-1 text-xs font-semibold {{ $roleBadgeClasses($user->role) }}">
                                        {{ $user->role->value }}
                                    </span>
                                </flux:table.cell>
                                <flux:table.cell align="center">
                                    <span class="inline-flex items-center gap-1.5 text-xs font-medium text-secondary-medium dark:text-secondary-soft">
                                        <span class="size-1.5 rounded-full {{ $user->email_verified_at ? 'bg-primary' : 'bg-secondary-light' }}"></span>
                                        {{ $user->email_verified_at ? __('Verified') : __('Pending') }}
                                    </span>
                                </flux:table.cell>
                                <flux:table.cell align="center">{{ $user->created_at?->format('M j, Y') }}</flux:table.cell>
                                <flux:table.cell align="center">
                                    @canany(['update', 'delete'], $user)
                                        <div class="inline-flex items-center justify-center gap-1">
                                            @can('update', $user)
                                                <flux:button
                                                    size="sm"
                                                    icon="pencil-square"
                                                    :class="$editButtonClasses.' size-8!'"
                                                    wire:click="openEditModal({{ $user->id }})"
                                                    :aria-label="__('Edit :name', ['name' => $user->name])"
                                                />
                                            @endcan

                                            @can('delete', $user)
                                                <flux:button
                                                    size="sm"
                                                    icon="trash"
                                                    :class="$deleteButtonClasses.' size-8!'"
                                                    wire:click="confirmUserDeletion({{ $user->id }})"
                                                    :aria-label="__('Delete :name', ['name' => $user->name])"
                                                />
                                            @endcan
                                        </div>
                                    @else
                                        <span class="rounded-md border border-secondary-light bg-secondary-soft px-2 py-1 text-xs font-medium text-secondary-dark">
                                            {{ __('Current account') }}
                                        </span>
                                    @endcanany
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>

            <div class="border-t border-secondary-soft px-3 pb-3 dark:border-secondary-medium">
                <flux:pagination
                    :paginator="$this->users"
                    class="text-secondary-medium! [&_button]:text-secondary-medium! [&_button:hover]:bg-secondary-soft! dark:text-secondary-soft! dark:[&_button]:text-secondary-soft! dark:[&_button:hover]:bg-secondary-medium!"
                    scroll-to="#user-directory-heading"
                />
            </div>
        @endif
    </div>

    <flux:modal
        wire:model="showUserModal"
        class="w-full max-w-xl border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!"
    >
        <form wire:submit="saveUser" class="space-y-5">
            <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                <flux:heading size="lg">
                    {{ $editingUserId === null ? __('Add user') : __('Edit user') }}
                </flux:heading>
                <flux:subheading>
                    {{ $editingUserId === null
                        ? __('Create an account and assign its access role.')
                        : __('Update account details or set a new password.') }}
                </flux:subheading>
            </div>

            <div class="grid gap-4 @md/directory:grid-cols-2">
                <flux:input wire:model="name" :class="$controlClasses" :label="__('Name')" type="text" autocomplete="name" required autofocus />
                <flux:input wire:model="email" :class="$controlClasses" :label="__('Email')" type="email" autocomplete="email" required />

                <div class="@md/directory:col-span-2">
                    <flux:select wire:model="selectedRole" :class="$controlClasses" :label="__('Role')" required>
                        @foreach ($this->roleOptions as $roleOption)
                            <flux:select.option
                                :value="$roleOption->value"
                                :wire:key="'role-option-'.$roleOption->value"
                                class="text-secondary-dark! hover:bg-secondary-soft! dark:bg-secondary-dark! dark:text-white! dark:hover:bg-secondary-medium!"
                            >
                                {{ $roleOption->value }}
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                    <p class="pt-1.5 text-xs text-secondary-medium dark:text-secondary-soft">
                        {{ match (UserRole::tryFrom($selectedRole)) {
                            UserRole::Admin => __('Full administrative access, including user management.'),
                            UserRole::Manager => __('Management access for programmes, plans, and delivery oversight.'),
                            UserRole::Meal => __('Monitoring, evaluation, accountability, and learning access.'),
                            default => __('Operational access for assigned projects, tasks, and reports.'),
                        } }}
                    </p>
                </div>

                <flux:input
                    wire:model="password"
                    :class="$controlClasses"
                    :label="$editingUserId === null ? __('Password') : __('New password')"
                    type="password"
                    autocomplete="new-password"
                    viewable
                    :required="$editingUserId === null"
                />
                <flux:input
                    wire:model="password_confirmation"
                    :class="$controlClasses"
                    :label="__('Confirm password')"
                    type="password"
                    autocomplete="new-password"
                    viewable
                    :required="$editingUserId === null"
                />
            </div>

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <flux:button type="button" variant="filled" :class="$secondaryButtonClasses.' min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto'" wire:click="closeUserModal">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary" :class="$primaryButtonClasses.' min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto'" data-test="save-user-button">
                    {{ $editingUserId === null ? __('Create user') : __('Save changes') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal
        wire:model="showDeleteModal"
        class="w-full max-w-lg border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!"
    >
        <form wire:submit="deleteUser" class="space-y-5">
            <div class="flex items-start gap-3">
                <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary text-white">
                    <flux:icon.trash class="size-5" />
                </div>
                <div>
                    <flux:heading size="lg">{{ __('Delete user?') }}</flux:heading>
                    <flux:subheading>
                        {{ __('Permanently delete :name. This action cannot be undone.', ['name' => $deletingUserName]) }}
                    </flux:subheading>
                </div>
            </div>

            @if ($deleteError)
                <div class="rounded-lg border border-primary bg-white p-3 text-sm text-primary dark:bg-primary dark:text-white" role="alert">
                    <p class="font-semibold">{{ __('Unable to delete user') }}</p>
                    <p>{{ $deleteError }}</p>
                </div>
            @endif

            <div class="grid grid-cols-2 gap-2 sm:flex sm:justify-end">
                <flux:button type="button" variant="filled" :class="$secondaryButtonClasses.' min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto'" wire:click="closeDeleteModal">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" :class="$primaryButtonClasses.' min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto'" data-test="confirm-delete-user-button">
                    {{ __('Delete user') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</section>
