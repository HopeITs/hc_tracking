{{-- The attach-evidence dialog and the delete-file confirmation on ⚡show.blade.php, each bound to its wire:model boolean (never :open). --}}
@use('App\Enums\FileType')
@use('Illuminate\Support\Str')
<x-ui.modal
    wire:model="showFileModal"
    size="md"
    :title="__('monthly_reports.attach_file_title')"
    :description="__('monthly_reports.attach_file_message', ['types' => Str::upper(implode(', ', FileType::values()))])"
    submit="saveFile"
    close="closeFileModal"
    required-note
>
    @if ($showFileModal)
        <div class="grid gap-y-4">
            <x-ui.form-select wire:model="newFileTypeId" :label="__('monthly_reports.evidence_type')" :placeholder="__('monthly_reports.choose_evidence_type')" required>
                @foreach ($this->evidenceTypes as $evidenceType)
                    <option value="{{ $evidenceType->id }}">{{ $evidenceType->localizedName() }}</option>
                @endforeach
            </x-ui.form-select>

            <x-ui.form-file
                wire:model="newFile"
                :label="__('monthly_reports.file')"
                :accept="collect(FileType::values())->map(fn ($extension) => '.'.$extension)->implode(',')"
                required
            />
        </div>
    @endif

    <x-slot:actions>
        <x-ui.button type="submit" tone="primary" wire:loading.attr="disabled" wire:target="newFile,saveFile" data-test="save-monthly-file">{{ __('monthly_reports.upload') }}</x-ui.button>
    </x-slot:actions>
</x-ui.modal>

<x-ui.confirm-modal
    wire:model="showDeleteFileModal"
    close="closeDeleteFileModal"
    :title="__('monthly_reports.delete_file_title')"
    :message="$showDeleteFileModal ? __('monthly_reports.delete_file_message', ['name' => $this->report->files->firstWhere('id', $deletingFileId)?->original_name ?? '']) : null"
    confirm="deleteFile"
    :confirm-label="__('monthly_reports.delete_file')"
    confirm-test="confirm-delete-monthly-file"
/>
