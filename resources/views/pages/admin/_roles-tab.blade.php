{{--
    The Roles tab of the user directory: the role list, the selected role's
    rename and permissions form, and the assigned-users modal. Included by
    ⚡users.blade.php; reads $this and $selectedRoleId.
--}}
        <div class="grid min-w-0 gap-6 xl:grid-cols-[19rem_minmax(0,1fr)]">
            <aside class="min-w-0 overflow-hidden rounded-xl border border-secondary-soft bg-white shadow-card dark:border-transparent dark:bg-surface-1 dark:shadow-raised">
                @can('access-management.manage-roles')
                    <form wire:submit="createRole" class="border-b border-secondary-soft p-3 dark:border-line" data-test="create-role-form">
                        <label for="new-role-name" class="mb-2 block text-sm font-bold text-secondary-dark dark:text-ink">{{ __('roles.new_role') }}</label>
                        <div class="grid gap-2">
                            <x-ui.input id="new-role-name" wire:model="newRoleName" :placeholder="__('roles.role_name_placeholder')" />
                            <x-ui.button type="submit" tone="primary">{{ __('roles.create_role') }}</x-ui.button>
                        </div>
                        @error('newRoleName') <p class="mt-1.5 text-xs text-status-critical">{{ $message }}</p> @enderror
                    </form>
                @endcan

                <div data-test="access-role-directory" class="divide-y divide-secondary-soft dark:divide-line">
                    @foreach ($this->roles as $roleRow)
                        <button type="button" wire:click="selectRole({{ $roleRow->id }})" wire:key="access-role-{{ $roleRow->id }}" @class([
                            'relative flex min-h-12 w-full items-center justify-between gap-3 px-3 py-2.5 text-start transition focus-visible:z-10 focus-visible:outline-2 focus-visible:outline-inset focus-visible:outline-secondary-medium',
                            'bg-secondary-soft/60 text-primary dark:text-brand-text dark:bg-white/[0.06]' => $selectedRoleId === $roleRow->id,
                            'bg-white text-secondary-medium hover:bg-secondary-soft/40 dark:bg-surface-1 dark:text-ink-muted dark:hover:bg-surface-2' => $selectedRoleId !== $roleRow->id,
                        ])>
                            @if ($selectedRoleId === $roleRow->id)
                                <span class="absolute inset-y-2 start-0 w-0.5 rounded-full bg-primary dark:bg-brand" aria-hidden="true"></span>
                            @endif
                            <span class="min-w-0 truncate text-sm font-bold">{{ $roleRow->name }}</span>
                            <span class="inline-flex min-w-6 shrink-0 items-center justify-center rounded-md border border-secondary-soft bg-white px-1.5 py-0.5 text-[0.65rem] font-semibold text-secondary-medium dark:border-line dark:bg-surface-1 dark:text-ink-muted">
                                {{ $roleRow->users_count }}
                            </span>
                        </button>
                    @endforeach
                </div>
            </aside>

            <section class="min-w-0 rounded-xl border border-secondary-soft bg-white shadow-card dark:border-transparent dark:bg-surface-1 dark:shadow-raised">
                @if ($this->selectedRole)
                    <div class="flex flex-wrap items-start justify-between gap-4 border-b border-secondary-soft px-6 py-4 dark:border-line">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="truncate text-xl font-bold text-secondary-dark dark:text-ink">{{ $this->selectedRole->name }}</h2>
                                @if ($this->selectedRoleIsProtected)
                                    <span class="rounded-md border border-primary px-2 py-1 text-[0.7rem] font-bold text-primary dark:text-brand-text">{{ __('roles.protected_role') }}</span>
                                @endif
                            </div>
                            <button type="button" wire:click="showAssignedUsers" class="group mt-1 inline-flex min-h-8 items-center gap-1.5 rounded-md text-sm font-semibold text-secondary-medium underline decoration-secondary-light underline-offset-4 transition hover:text-primary dark:hover:text-brand-text hover:decoration-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium dark:text-ink-muted" aria-haspopup="dialog">
                                <flux:icon.users class="size-4 shrink-0 text-primary dark:text-brand-text" />
                                <span>{{ trans_choice('roles.users_count', $this->selectedRole->users_count, ['count' => $this->selectedRole->users_count]) }}</span>
                            </button>
                            @if ($this->selectedRoleIsProtected)
                                <p class="mt-1.5 text-xs font-semibold text-primary dark:text-brand-text">{{ __('roles.protected_role_help') }}</p>
                            @elseif (! $this->canEditSelectedRolePermissions && auth()->user()->can('access-management.manage-permissions'))
                                <p class="mt-1.5 text-xs font-semibold text-primary dark:text-brand-text" data-test="role-admin-only-note">{{ __('roles.admin_only_role_help') }}</p>
                            @endif
                            @if (! $this->selectedRoleIsProtected && $this->selectedRoleIsBuiltIn)
                                <p class="mt-1.5 text-xs text-secondary-medium dark:text-ink-muted" data-test="role-built-in-note">{{ __('roles.built_in_role_help') }}</p>
                            @endif
                        </div>

                        @can('access-management.manage-roles')
                            @unless ($this->selectedRoleIsProtected || $this->selectedRoleIsBuiltIn)
                                <div class="grid w-full items-end gap-2 sm:w-auto sm:grid-cols-[minmax(13rem,1fr)_auto]">
                                    <x-ui.input id="selected-role-name" wire:model="selectedRoleName" autocomplete="off" spellcheck="false" :label="__('roles.rename_role')" :disabled="! $this->canManageSelectedRole" />
                                    <x-ui.button type="button" wire:click="renameRole" :disabled="! $this->canManageSelectedRole" :title="$this->canManageSelectedRole ? null : __('roles.admin_only_role_help')">{{ __('roles.rename_role') }}</x-ui.button>
                                </div>
                            @endunless
                        @endcan
                    </div>
                    @error('selectedRoleName') <p class="px-6 pt-2 text-xs text-status-critical">{{ $message }}</p> @enderror

                    <form wire:submit="saveRolePermissions">
                        <div class="flex flex-wrap items-center justify-between gap-3 px-6 pt-4">
                            <h3 class="text-sm font-bold text-secondary-dark dark:text-ink">{{ __('roles.permissions') }}</h3>
                            @can('access-management.manage-permissions')
                                <div class="flex flex-wrap gap-2">
                                    <x-ui.button type="button" tone="ghost" wire:click="selectAllRolePermissions" :disabled="! $this->canEditSelectedRolePermissions">{{ __('roles.select_all') }}</x-ui.button>
                                    <x-ui.button type="button" tone="ghost" wire:click="clearRolePermissions" :disabled="! $this->canEditSelectedRolePermissions">{{ __('roles.clear_all') }}</x-ui.button>
                                </div>
                            @endcan
                        </div>

                        <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,18rem),1fr))] gap-3 p-6">
                            @foreach ($this->permissionGroups as $group)
                                <fieldset class="min-w-0 rounded-lg border border-secondary-soft p-3.5 dark:border-line">
                                    <legend class="px-1 text-xs font-bold uppercase tracking-wide text-secondary-medium dark:text-ink-muted">{{ $group['label'] }}</legend>
                                    <flux:checkbox.group
                                        wire:model="rolePermissionIds"
                                        style="--color-accent: var(--color-primary); --color-accent-foreground: var(--color-white);"
                                        class="mt-1! grid! grid-cols-[repeat(auto-fit,minmax(8.5rem,1fr))]! gap-x-3! gap-y-2! [&>[data-flux-field]]:mb-0!"
                                    >
                                        @foreach ($group['permissions'] as $permission)
                                            <flux:checkbox
                                                value="{{ $permission->id }}"
                                                :label="__('access.actions.'.str($permission->name)->afterLast('.'))"
                                                :disabled="! $this->canEditSelectedRolePermissions || ! in_array($permission->id, $this->grantablePermissionIds, true)"
                                                :title="in_array($permission->id, $this->grantablePermissionIds, true) ? null : __('roles.admin_only_permission_help')"
                                                wire:key="role-permission-{{ $permission->id }}"
                                            />
                                        @endforeach
                                    </flux:checkbox.group>
                                </fieldset>
                            @endforeach
                        </div>

                        @can('access-management.manage-permissions')
                            <div class="sticky bottom-0 flex justify-end border-t border-secondary-soft bg-white/95 px-6 py-3 backdrop-blur-sm dark:border-line dark:bg-surface-1/95">
                                <x-ui.button type="submit" tone="primary" data-test="save-role-permissions" :disabled="! $this->canEditSelectedRolePermissions">{{ __('roles.save_permissions') }}</x-ui.button>
                            </div>
                        @endcan
                    </form>
                @endif
            </section>
        </div>

        <x-ui.modal
            wire:model="showAssignedUsersModal"
            size="lg"
            :title="trans_choice('access.assigned-users', $this->selectedRole?->users_count ?? 0)"
            :description="$this->selectedRole?->name"
            close="closeAssignedUsersModal"
            :cancel-label="__('roles.close')"
        >
            @if ($showAssignedUsersModal)
            <div class="divide-y divide-secondary-soft dark:divide-line">
                @forelse ($this->selectedRoleUsers as $roleUser)
                    <div class="flex min-w-0 items-center justify-between gap-3 px-1 py-3" wire:key="assigned-role-user-{{ $roleUser->id }}">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-bold text-secondary-dark dark:text-ink">{{ $roleUser->name }}</p>
                            <p class="truncate text-sm text-secondary-medium dark:text-ink-muted">{{ $roleUser->email }}</p>
                        </div>
                    </div>
                @empty
                    <p class="py-8 text-center text-sm text-secondary-medium dark:text-ink-muted">{{ __('access.no-assigned-users') }}</p>
                @endforelse
            </div>
            @endif
        </x-ui.modal>
