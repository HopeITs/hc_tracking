    <x-ui.modal
        wire:model="showFileModal"
        size="md"
        :title="$editingFileId === null ? __('projects.add_file') : __('projects.edit_file')"
        submit="saveFile"
        close="closeFileModal"
        required-note
    >
        @if ($showFileModal)
            <div class="grid gap-y-4">
                @if ($editingFileId === null)
                    <x-ui.form-file wire:model="newFile" :label="__('projects.choose_a_file')" required />
                @endif

                <div class="grid grid-cols-2 gap-x-4 gap-y-4">
                    <x-ui.form-select wire:model="fileCategory" :label="__('projects.file_category')" :placeholder="__('common.select')" required>
                        @foreach (\App\Enums\MediaCollection::cases() as $collectionOption)
                            @continue(in_array($collectionOption->value, $this->hiddenCollections(), true))
                            <option value="{{ $collectionOption->value }}">{{ $collectionOption->label() }}</option>
                        @endforeach
                    </x-ui.form-select>

                    <x-ui.form-input wire:model="fileDisplayName" :label="__('projects.file_display_name')" :required="$editingFileId !== null" />
                </div>
            </div>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="save-project-file-button">{{ __('common.save_changes') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.modal>

    <x-ui.confirm-modal
        wire:model="confirmingFileDelete"
        :title="__('projects.delete_this_file')"
        :message="$deletingFileId !== null && ($deletingMedia = $this->project->media->firstWhere('id', $deletingFileId)) !== null ? __('projects.deleting_the_file_also_removes_it_from_storage', ['name' => $deletingMedia->original_name]) : null"
        confirm="deleteFile"
        :confirm-label="__('projects.delete_this_file')"
        confirm-test="confirm-delete-project-file"
    />

    <x-tasks.add-task-modal :open="$showAddTaskModal" test-prefix="new-project-task" :user-options="$this->userOptions" />
