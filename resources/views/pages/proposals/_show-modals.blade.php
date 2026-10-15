{{--
    Every dialog of proposals/show, in page order: status, proposal form,
    agreement, tranche, unlink, file, file preview, file delete and add
    task. Included by ⚡show.blade.php.
--}}
@use('App\Enums\FileType')
@use('App\Enums\MediaCollection')
    @include('pages.proposals._status-modals')

    @include('pages.proposals._approve-modal')

    @include('pages.proposals._proposal-form')

    <x-funding.agreement-modal
        :open="$showAgreementModal"
        :editing="$editingAgreementId !== null"
        :donor-options="$showAgreementModal ? $this->donorOptions : []"
        :warning="$showAgreementModal ? $this->agreementProposalWarning : null"
        :delete-blocker="$showAgreementModal ? $this->agreementDeleteBlocker : null"
        :locked-currency="$showAgreementModal && $this->agreementCurrencyLocked ? $agreementCurrency : null"
        :confirming-delete="$confirmingAgreementDelete"
        :locked-proposal-name="$this->proposal->name.' · '.$this->proposal->internal_project_number"
    />

    @canany(['transfers.create', 'transfers.update'])
        <x-funding.transfer-modal
            :open="$showTransferModal"
            :editing="$editingTransferId !== null"
            :agreement-options="$showTransferModal ? $this->agreementOptions : []"
            :account-options="$showTransferModal ? $this->accountOptions : []"
            :confirming-delete="$confirmingTransferDelete"
        />
    @endcanany

    <x-ui.confirm-modal
        wire:model="showUnlinkModal"
        tone="neutral"
        :title="__('proposals.stop_funding_this_project')"
        :message="$unlinkingProjectId !== null && ($unlinking = $this->projects->firstWhere('id', $unlinkingProjectId)) !== null ? __('proposals.unlink_project_consequence', ['name' => $unlinking->name]) : null"
        confirm="unlinkProject"
        :confirm-label="__('proposals.unlink_project')"
        confirm-test="confirm-unlink-project"
    >
        @if ($showUnlinkModal)
            @if ($unlinkUnreportedTargetCount > 0)
                <p class="text-sm text-secondary-dark dark:text-ink" data-unlink-unreported-targets>{{ trans_choice('proposals.unlink_project_unreported_targets', $unlinkUnreportedTargetCount, ['count' => $unlinkUnreportedTargetCount, 'name' => $this->projects->firstWhere('id', $unlinkingProjectId)?->name ?? '']) }}</p>
            @endif
            <flux:error name="project" />
        @endif
    </x-ui.confirm-modal>

    <x-ui.modal
        wire:model="showFileModal"
        size="md"
        :title="$editingFileId === null ? __('proposals.upload_file') : __('proposals.edit_file')"
        :description="__('reports.allowed_file_types_and_size', ['types' => \Illuminate\Support\Str::upper(implode(', ', FileType::values())), 'size' => '5 MB'])"
        submit="saveFile"
        close="closeFileModal"
        required-note
    >
        @if ($showFileModal)
            <div class="grid gap-y-4">
                <x-ui.form-file
                    wire:model="newFile"
                    :label="$editingFileId === null ? __('proposals.choose_a_file') : __('reports.replace_file_optional')"
                    :accept="collect(FileType::values())->map(fn ($extension) => '.'.$extension)->implode(',')"
                    :required="$editingFileId === null"
                />

                <div class="grid grid-cols-2 gap-x-4 gap-y-4">
                    <x-ui.form-select wire:model="fileCategory" :label="__('proposals.file_category')" :placeholder="__('common.select')" required>
                        @foreach (MediaCollection::cases() as $collectionOption)
                            @continue(in_array($collectionOption->value, $this->hiddenCollections(), true))
                            <option value="{{ $collectionOption->value }}">{{ $collectionOption->label() }}</option>
                        @endforeach
                    </x-ui.form-select>

                    <x-ui.form-input wire:model="fileDisplayName" :label="__('proposals.file_display_name')" :placeholder="$editingFileId === null ? __('reports.defaults_to_the_uploaded_file_name') : null" :required="$editingFileId !== null" />
                </div>
            </div>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" wire:loading.attr="disabled" wire:target="newFile,saveFile" data-test="save-proposal-file-button">
                {{ $editingFileId === null ? __('proposals.upload_file') : __('common.save_changes') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.modal>

    @php
        $previewFile = $showPreviewModal ? $this->documents->firstWhere('id', $previewingFileId) : null;
        $previewMeta = $previewFile === null ? null : collect([
            \Illuminate\Support\Str::upper($previewFile->type->value),
            $previewFile->collection->label(),
            $previewFile->mediable_type === 'project' && $previewFile->mediable !== null ? $previewFile->mediable->name : null,
            $previewFile->size !== null ? \Illuminate\Support\Number::fileSize($previewFile->size, precision: 1) : null,
            $this->documents->count() > 1 ? __('reports.preview_position', [
                'position' => $this->documents->search(fn ($candidate) => $candidate->id === $previewFile->id) + 1,
                'total' => $this->documents->count(),
            ]) : null,
        ])->filter()->implode(' · ');
        $previewDownloadUrl = $previewFile ? $this->documentDownloadUrl($previewFile) : null;
    @endphp

    <x-files.preview-modal
        :file="$previewFile"
        :name="$previewFile?->original_name ?? ''"
        :description="$previewMeta"
        :preview-url="$previewFile ? route('proposals.files.preview', [$this->proposal, $previewFile]) : null"
        :download-url="$previewDownloadUrl"
        :count="$this->documents->count()"
        :compact="false"
        marker
    />
    <x-ui.confirm-modal
        wire:model="confirmingFileDelete"
        :title="__('proposals.delete_this_file')"
        :message="$deletingFileId !== null && ($deletingMedia = $this->proposalFiles->firstWhere('id', $deletingFileId)) !== null ? __('proposals.deleting_the_file_also_removes_it_from_storage', ['name' => $deletingMedia->original_name]) : null"
        confirm="deleteFile"
        :confirm-label="__('proposals.delete_this_file')"
        confirm-test="confirm-delete-proposal-file"
    />

    <x-tasks.add-task-modal :open="$showAddTaskModal" test-prefix="new-proposal-task" />
