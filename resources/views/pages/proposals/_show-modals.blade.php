{{--
    Every dialog of proposals/show, in page order: status, details,
    agreement, tranche, unlink, file, file preview, file delete and add
    task. Included by ⚡show.blade.php.
--}}
@use('App\Enums\FileType')
@use('App\Enums\MediaCollection')
@use('App\Enums\ProposalStatus')
    <x-ui.modal
        wire:model="showStatusModal"
        size="md"
        :title="__('proposals.change_status')"
        :description="__('proposals.the_new_status_and_your_reason_are_recorded_together_as_one')"
        submit="changeStatus"
        required-note
    >
        @if ($showStatusModal)
            <div class="grid grid-cols-2 gap-x-4 gap-y-4">
                <x-ui.form-select wire:model="newStatus" :label="__('proposals.new_status')" required autofocus>
                    @foreach (ProposalStatus::cases() as $statusCase)
                        <option value="{{ $statusCase->value }}">{{ $statusCase->label() }} — {{ $statusCase->phase()->label() }}</option>
                    @endforeach
                </x-ui.form-select>

                <x-ui.form-input wire:model="statusDate" :label="__('proposals.effective_date')" type="date" required />

                <x-ui.form-textarea wire:model="statusContext" :label="__('proposals.why_did_it_change')" required class="col-span-2" />
            </div>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="save-status-button">{{ __('proposals.record_change') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.modal>

    <x-ui.modal
        wire:model="showDetailsModal"
        size="xl"
        :title="__('proposals.edit_details')"
        :description="__('proposals.status_moves_through_change_status_so_it_is_always_recorded')"
        submit="saveDetails"
        required-note
    >
        @if ($showDetailsModal)
            <x-ui.form-section :title="__('common.section_details')" :fields="['detailsName', 'detailsNotes']">
                <div class="grid gap-y-3">
                    <x-ui.form-input wire:model="detailsName" :label="__('proposals.proposal_name')" maxlength="255" required autofocus />
                    <x-ui.form-textarea wire:model="detailsNotes" :label="__('common.notes')" />
                </div>
            </x-ui.form-section>

            <x-ui.form-section :title="__('common.section_schedule')" :fields="['detailsPlannedBegin', 'detailsPlannedEnd', 'detailsActualBegin', 'detailsActualEnd']">
                <div class="grid grid-cols-2 gap-x-4 gap-y-3 @4xl/modal:grid-cols-4">
                    <x-ui.form-input wire:model="detailsPlannedBegin" :label="__('proposals.planned_start')" type="date" required />
                    <x-ui.form-input wire:model="detailsPlannedEnd" :label="__('proposals.planned_end')" type="date" required />
                    <x-ui.form-input wire:model="detailsActualBegin" :label="__('proposals.actual_start')" type="date" />
                    <x-ui.form-input wire:model="detailsActualEnd" :label="__('proposals.actual_end')" type="date" />
                </div>
            </x-ui.form-section>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="save-details-button">{{ __('common.save_changes') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.modal>

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
    />

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
