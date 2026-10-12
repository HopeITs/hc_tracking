{{-- The upload / edit file dialog, the file preview and the delete-file confirmation on ⚡show.blade.php. --}}
@use('App\Enums\FileType')
@use('Illuminate\Support\Str')
    <x-ui.modal
        wire:model="showFileModal"
        size="md"
        :title="$editingFileId === null ? __('tasks.upload_file') : __('tasks.edit_file')"
        :description="__('tasks.allowed_file_types_and_size', ['types' => Str::upper(implode(', ', FileType::values())), 'size' => '5 MB'])"
        submit="saveFile"
        close="closeFileModal"
        required-note
    >
        @if ($showFileModal)
            <div class="grid gap-y-4">
                <x-ui.form-file
                    wire:model="newFile"
                    :label="$editingFileId === null ? __('tasks.choose_a_file') : __('tasks.replace_file_optional')"
                    :accept="collect(FileType::values())->map(fn ($extension) => '.'.$extension)->implode(',')"
                    :required="$editingFileId === null"
                />

                <div class="grid grid-cols-2 gap-x-4 gap-y-4">
                    <x-ui.form-input wire:model="fileDisplayName" :label="__('tasks.file_display_name')" :placeholder="$editingFileId === null ? __('tasks.defaults_to_the_uploaded_file_name') : null" :required="$editingFileId !== null" />
                    <x-ui.form-input wire:model="fileDate" :label="__('tasks.attachment_date')" type="date" required />
                </div>
            </div>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" wire:loading.attr="disabled" wire:target="newFile,saveFile" data-test="save-file-button">
                {{ $editingFileId === null ? __('tasks.upload_file') : __('common.save_changes') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.modal>

    @php
        $previewFile = $showPreviewModal ? $this->attachments->firstWhere('id', $previewingFileId) : null;
        $previewMeta = $previewFile === null ? null : collect([
            Str::upper($previewFile->type->value),
            $previewFile->size !== null ? \Illuminate\Support\Number::fileSize($previewFile->size, precision: 1) : null,
            $previewFile->date?->translatedFormat(__('formats.date')),
            $this->attachments->count() > 1 ? __('tasks.preview_position', [
                'position' => $this->attachments->search(fn ($candidate) => $candidate->id === $previewFile->id) + 1,
                'total' => $this->attachments->count(),
            ]) : null,
        ])->filter()->implode(' · ');
    @endphp

    <x-files.preview-modal
        :file="$previewFile"
        :name="$previewFile?->displayName() ?? ''"
        :description="$previewMeta"
        :preview-url="$previewFile ? route('tasks.files.preview', [$this->task, $previewFile]) : null"
        :download-url="$previewFile ? route('tasks.files.download', [$this->task, $previewFile]) : null"
        :count="$this->attachments->count()"
    />
    <x-ui.confirm-modal
        wire:model="showDeleteFileModal"
        close="closeDeleteFileModal"
        :title="$showDeleteFileModal && $deletingFileId !== null && ($deletingAttachment = $this->attachments->firstWhere('id', $deletingFileId)) !== null ? __('common.delete_name', ['name' => $deletingAttachment->displayName()]) : __('common.delete')"
        confirm="deleteFile"
        :confirm-label="__('common.delete')"
        confirm-test="confirm-delete-file-button"
    />
