{{-- The upload / edit file dialog, the file preview and the delete-file confirmation on ⚡show.blade.php. --}}
@use('App\Enums\FileType')
@use('Illuminate\Support\Str')
    <x-ui.modal
        wire:model="showFileModal"
        size="md"
        :title="$editingFileId === null ? __('reports.upload_file') : __('reports.edit_file')"
        :description="__('reports.allowed_file_types_and_size', ['types' => Str::upper(implode(', ', FileType::values())), 'size' => '5 MB'])"
        submit="saveFile"
        close="closeFileModal"
        required-note
    >
        @if ($showFileModal)
            <div class="grid gap-y-4">
                <x-ui.form-file
                    wire:model="newFile"
                    :label="$editingFileId === null ? __('reports.choose_a_file') : __('reports.replace_file_optional')"
                    :accept="collect(FileType::values())->map(fn ($extension) => '.'.$extension)->implode(',')"
                    :required="$editingFileId === null"
                />

                <div class="grid grid-cols-2 gap-x-4 gap-y-4">
                    <x-ui.form-input wire:model="fileDisplayName" :label="__('reports.file_display_name')" :placeholder="$editingFileId === null ? __('reports.defaults_to_the_uploaded_file_name') : null" :required="$editingFileId !== null" />
                    <x-ui.form-input wire:model="fileDate" :label="__('reports.document_date')" type="date" required />
                </div>
            </div>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" wire:loading.attr="disabled" wire:target="newFile,saveFile" data-test="save-file-button">
                {{ $editingFileId === null ? __('reports.upload_file') : __('common.save_changes') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.modal>

    @php
        $previewFile = $showPreviewModal ? $this->files->firstWhere('id', $previewingFileId) : null;
        $previewMeta = $previewFile === null ? null : collect([
            Str::upper($previewFile->type->value),
            $previewFile->size !== null ? \Illuminate\Support\Number::fileSize($previewFile->size, precision: 1) : null,
            $previewFile->date?->translatedFormat(__('formats.date')),
            $this->files->count() > 1 ? __('reports.preview_position', [
                'position' => $this->files->search(fn ($candidate) => $candidate->id === $previewFile->id) + 1,
                'total' => $this->files->count(),
            ]) : null,
        ])->filter()->implode(' · ');
    @endphp

    <x-files.preview-modal
        :file="$previewFile"
        :name="$previewFile?->displayName() ?? ''"
        :description="$previewMeta"
        :preview-url="$previewFile ? route('reports.files.preview', [$report, $previewFile]) : null"
        :download-url="$previewFile ? route('reports.files.download', [$report, $previewFile]) : null"
        :count="$this->files->count()"
    />
    <x-ui.confirm-modal
        wire:model="showDeleteFileModal"
        close="closeDeleteFileModal"
        :title="__('reports.delete_this_file')"
        :message="$showDeleteFileModal ? __('reports.deleting_the_file_also_removes_it_from_storage', ['name' => $this->files->firstWhere('id', $deletingFileId)?->displayName() ?? '']) : null"
        confirm="deleteFile"
        :confirm-label="__('reports.delete_this_file')"
        confirm-test="confirm-delete-file-button"
    />
