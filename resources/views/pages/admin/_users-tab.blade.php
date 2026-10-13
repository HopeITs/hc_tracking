{{--
    The Users tab of the user directory: filters, the user list, and the
    selected user's access form. Included by ⚡users.blade.php; reads $this,
    $search, $role, $status, $selectedUserId and $userRoleIds.
--}}
@use('Illuminate\Support\Facades\Gate')

        <div class="directory-filters grid min-w-0 gap-3 @sm/directory:grid-cols-2 @3xl/directory:grid-cols-3">
            <x-ui.input id="access-user-search" type="search" icon="magnifying-glass" :label="__('users.search_users')" :placeholder="__('users.search_users')" wire:model.live.debounce.350ms="search" clearable />

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
        </div>

        <div class="grid min-w-0 gap-6 xl:grid-cols-[21rem_minmax(0,1fr)]">
            <section class="flex min-w-0 flex-col overflow-hidden rounded-xl border border-secondary-soft bg-white shadow-card dark:border-transparent dark:bg-surface-1 dark:shadow-raised">
                <div class="max-h-[min(62vh,44rem)] divide-y divide-secondary-soft overflow-y-auto overscroll-contain dark:divide-line">
                    @forelse ($this->users as $user)
                        <button type="button" wire:click="selectUser({{ $user->id }})" wire:key="access-user-{{ $user->id }}" @class([
                            'group relative flex min-h-[4.5rem] w-full items-center gap-3 px-3 py-2.5 text-start transition focus-visible:z-10 focus-visible:outline-2 focus-visible:outline-inset focus-visible:outline-primary',
                            'bg-secondary-soft/60 dark:bg-brand/10' => $selectedUserId === $user->id,
                            'bg-white hover:bg-secondary-soft/40 dark:bg-surface-1 dark:hover:bg-surface-2' => $selectedUserId !== $user->id,
                        ])>
                            @if ($selectedUserId === $user->id)
                                <span class="absolute inset-y-2 start-0 w-0.5 rounded-full bg-primary dark:bg-brand" aria-hidden="true"></span>
                            @endif

                            <span class="min-w-0 flex-1">
                                <span @class([
                                    'block truncate text-sm font-bold',
                                    'text-primary dark:text-brand-text' => $selectedUserId === $user->id,
                                    'text-secondary-dark dark:text-ink' => $selectedUserId !== $user->id,
                                ])>{{ $user->name }}</span>
                                <span class="block truncate text-xs text-secondary-medium dark:text-ink-muted">{{ $user->email }}</span>
                                <span class="mt-1 flex flex-wrap gap-1">
                                    @forelse ($user->roles->take(2) as $userRole)
                                        <span class="rounded-md border border-secondary-soft bg-white px-1.5 py-0.5 text-[0.65rem] font-semibold leading-none text-secondary-medium dark:border-line dark:bg-surface-1 dark:text-ink-muted">
                                            {{ $userRole->name }}
                                        </span>
                                    @empty
                                        <span class="rounded-md border border-secondary-soft bg-white px-1.5 py-0.5 text-[0.65rem] font-semibold leading-none text-secondary-light dark:border-line dark:bg-surface-1 dark:text-ink-muted">
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

                <div class="border-t border-secondary-soft px-3 pb-3 dark:border-line">
                    <flux:pagination
                        :paginator="$this->users"
                        scroll-to="#access-management-heading"
                        class="text-secondary-medium! [&_button]:text-secondary-medium! [&_button:hover]:bg-secondary-soft! dark:text-ink-muted! dark:[&_button]:text-ink-muted! dark:[&_button:hover]:bg-surface-2!"
                    />
                </div>
            </section>

            <section class="flex min-w-0 flex-col rounded-xl border border-secondary-soft bg-white shadow-card dark:border-transparent dark:bg-surface-1 dark:shadow-raised">
                @if ($this->selectedUser)
                    @php
                        $selectedUser = $this->selectedUser;
                        $checkboxAccentStyle = '--color-accent: var(--color-primary); --color-accent-foreground: var(--color-white);';
                    @endphp
                    <form wire:submit="saveUserAccess">
                        <div class="flex flex-wrap items-center gap-3 border-b border-secondary-soft px-6 py-4 dark:border-line">
                            <div class="min-w-0 flex-1">
                                <h2 class="truncate text-lg font-bold text-secondary-dark dark:text-ink">{{ $selectedUser->name }}</h2>
                                <p class="truncate text-sm text-secondary-medium dark:text-ink-muted">{{ $selectedUser->email }}</p>
                            </div>
                        </div>

                        <div class="space-y-8 p-6">
                            <section aria-labelledby="assigned-roles-title">
                                <h3 id="assigned-roles-title" class="mb-3 text-sm font-bold text-secondary-dark dark:text-ink">{{ __('access.assigned-roles') }}</h3>
                                <flux:checkbox.group
                                    wire:model.live="userRoleIds"
                                    style="{{ $checkboxAccentStyle }}"
                                    class="grid! grid-cols-[repeat(auto-fit,minmax(min(100%,10rem),1fr))]! gap-x-4! gap-y-2.5! [&>[data-flux-field]]:mb-0!"
                                >
                                    @foreach ($this->roleOptions as $roleOption)
                                        <flux:checkbox
                                            value="{{ $roleOption->id }}"
                                            :label="$roleOption->name"
                                            :disabled="! Gate::check('update', $selectedUser) || ! Gate::check('assignRole', [App\Models\User::class, $roleOption])"
                                            wire:key="user-role-{{ $roleOption->id }}"
                                        />
                                    @endforeach
                                </flux:checkbox.group>
                                @error('userRoleIds') <p class="mt-1.5 text-xs text-status-critical">{{ $message }}</p> @enderror
                            </section>

                            <section class="border-t border-secondary-soft pt-5 dark:border-line" aria-labelledby="active-role-title">
                                <label id="active-role-title" for="user-active-role" class="mb-2 block text-sm font-bold text-secondary-dark dark:text-ink">{{ __('access.active-role') }}</label>
                                <x-ui.select id="user-active-role" wire:model.live="userActiveRoleId" :disabled="! Gate::check('update', $selectedUser)" class="sm:max-w-sm">
                                    <flux:select.option value="">—</flux:select.option>
                                    @foreach ($this->roleOptions->whereIn('id', $userRoleIds) as $roleOption)
                                        <flux:select.option :value="$roleOption->id">{{ $roleOption->name }}</flux:select.option>
                                    @endforeach
                                </x-ui.select>
                                @error('userActiveRoleId') <p class="mt-1.5 text-xs text-status-critical">{{ $message }}</p> @enderror
                            </section>

                            <section class="border-t border-secondary-soft pt-5 dark:border-line" aria-labelledby="direct-permissions-title">
                                <h3 id="direct-permissions-title" class="text-sm font-bold text-secondary-dark dark:text-ink">{{ __('access.direct-permissions') }}</h3>
                                <p class="mt-1 text-xs leading-5 text-secondary-medium dark:text-ink-muted">{{ __('access.direct-permissions-help') }}</p>

                                <div class="mt-4 grid grid-cols-[repeat(auto-fit,minmax(min(100%,18rem),1fr))] gap-3">
                                    @foreach ($this->permissionGroups as $group)
                                        <fieldset class="min-w-0 rounded-lg border border-secondary-soft p-3.5 dark:border-line">
                                            <legend class="px-1 text-xs font-bold uppercase tracking-wide text-secondary-medium dark:text-ink-muted">{{ $group['label'] }}</legend>
                                            <flux:checkbox.group
                                                wire:model.live="userPermissionIds"
                                                style="{{ $checkboxAccentStyle }}"
                                                class="mt-1! grid! grid-cols-[repeat(auto-fit,minmax(8.5rem,1fr))]! gap-x-3! gap-y-2! [&>[data-flux-field]]:mb-0!"
                                            >
                                                @foreach ($group['permissions'] as $permission)
                                                    <flux:checkbox
                                                        value="{{ $permission->id }}"
                                                        :label="__('access.actions.'.str($permission->name)->afterLast('.'))"
                                                        :disabled="! Gate::check('update', $selectedUser) || ! Gate::check('grantPermission', [App\Models\User::class, $permission])"
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
                            <div class="sticky bottom-0 flex justify-end border-t border-secondary-soft bg-white/95 px-6 py-3 backdrop-blur-sm dark:border-line dark:bg-surface-1/95">
                                <x-ui.button type="submit" tone="primary" data-test="save-user-access-button">{{ __('access.save-access') }}</x-ui.button>
                            </div>
                        @endcan
                    </form>
                @else
                    <x-ui.empty-state icon="user" :heading="__('access.select-employee')" :body="__('access.select-employee-help')" class="min-h-[clamp(22rem,55vh,38rem)] flex-1" />
                @endif
            </section>
        </div>
