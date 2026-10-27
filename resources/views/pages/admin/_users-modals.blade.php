{{--
    The add-user modal and the delete-user confirmation of the user
    directory. Included by ⚡users.blade.php; reads $this, $showCreateUserModal,
    $showDeleteModal, $deletingUserName and $deleteError.
--}}
    <x-ui.modal
        wire:model="showCreateUserModal"
        size="md"
        :title="__('users.add_user')"
        :description="__('users.give_an_hr_system_account_access_and_choose_its_role_people')"
        submit="createUser"
        close="closeCreateModal"
        required-note
    >
        @if ($showCreateUserModal)
        <div class="grid gap-y-4">
            <x-ui.form-select wire:model="hrUserId" :label="__('users.hr_account')" :placeholder="__('users.select_an_hr_account')" required autofocus>
                @foreach ($this->hrAccountOptions as $hrAccountOption)
                    <option value="{{ $hrAccountOption->id }}">{{ $hrAccountOption->email }}@if ($hrAccountOption->employee) · {{ $hrAccountOption->employee->name }} · {{ $hrAccountOption->employee->secondary_name }}@endif</option>
                @endforeach
            </x-ui.form-select>

            <x-ui.form-select wire:model="initialRoleId" :label="__('users.role')">
                <option value="">{{ __('users.default_role_option', ['role' => config('access-control.default_role')]) }}</option>
                @foreach ($this->roleOptions as $roleOption)
                    @can('assignRole', [App\Models\User::class, $roleOption])
                        <option value="{{ $roleOption->id }}">{{ $roleOption->name }}</option>
                    @endcan
                @endforeach
            </x-ui.form-select>
        </div>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="save-user-button">{{ __('users.create_user') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.modal>

    <x-ui.confirm-modal
        wire:model="showDeleteModal"
        close="closeDeleteModal"
        :title="__('users.delete_user_2')"
        :message="__('users.permanently_delete_name_this_action_cannot_be_undone', ['name' => $deletingUserName])"
        confirm="deleteUser"
        :confirm-label="__('users.delete_user')"
        confirm-test="confirm-delete-user-button"
    >
        @if ($deleteError)
            <div class="rounded-lg border border-primary bg-secondary-soft/60 p-3 text-sm text-status-critical" role="alert">
                <p class="font-semibold">{{ __('users.unable_to_delete_user') }}</p>
                <p>{{ $deleteError }}</p>
            </div>
        @endif
    </x-ui.confirm-modal>
