{{--
    Every dialog of proposals/show, in page order: status, proposal form,
    agreement, tranche, unlink, file, file preview, file delete and add
    task. Included by ⚡show.blade.php.
--}}
@use('App\Enums\FileType')
@use('App\Enums\MediaCollection')
    @include('pages.proposals._status-modals')

    @include('pages.proposals._approve-modal')

    @include('pages.proposals._final-report-modal')

    {{-- W-06 (D-58): a hold keeps the status, so both dialogs state that in one
         sentence and neither offers a status picker. --}}
    <x-ui.confirm-modal
        wire:model="showHoldModal"
        tone="neutral"
        close="closeHoldModal"
        :title="__('proposals.put_on_hold')"
        :message="__('proposals.put_on_hold_consequence')"
        confirm="putOnHold"
        :confirm-label="__('proposals.put_on_hold')"
        confirm-test="confirm-put-on-hold"
        data-test="hold-modal"
    >
        @if ($showHoldModal)
            @if ($holdStale || $errors->has('holdGate'))
                <div class="mb-4 rounded-lg border border-primary bg-secondary-soft/60 p-3 text-sm text-status-critical" role="alert" data-test="hold-gate-alert">
                    <ul class="list-disc ps-5">
                        @foreach ($errors->get('holdGate') as $holdGateMessage)
                            <li>{{ $holdGateMessage }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="grid gap-4">
                <x-ui.form-textarea wire:model="holdReason" :label="__('proposals.why_put_on_hold')" rows="3" required autofocus data-test="hold-reason" />
                <x-ui.form-input
                    wire:model="holdRemindOn"
                    :label="__('proposals.remind_me_on')"
                    type="date"
                    :min="now()->addDay()->toDateString()"
                    :help="__('proposals.remind_me_on_help')"
                    required
                    data-test="hold-remind-on"
                />
            </div>
        @endif
    </x-ui.confirm-modal>

    <x-ui.confirm-modal
        wire:model="showOffHoldModal"
        tone="neutral"
        close="closeOffHoldModal"
        :title="__('proposals.take_off_hold')"
        :message="__('proposals.take_off_hold_consequence')"
        confirm="takeOffHold"
        :confirm-label="__('proposals.take_off_hold')"
        confirm-test="confirm-take-off-hold"
        data-test="off-hold-modal"
    >
        @if ($showOffHoldModal)
            @if ($holdStale || $errors->has('holdGate'))
                <div class="mb-4 rounded-lg border border-primary bg-secondary-soft/60 p-3 text-sm text-status-critical" role="alert" data-test="off-hold-gate-alert">
                    <ul class="list-disc ps-5">
                        @foreach ($errors->get('holdGate') as $offHoldGateMessage)
                            <li>{{ $offHoldGateMessage }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <x-ui.form-textarea wire:model="offHoldNote" :label="__('proposals.off_hold_note')" rows="3" data-test="off-hold-note" />
        @endif
    </x-ui.confirm-modal>

    @include('pages.proposals._proposal-form')

    <x-funding.agreement-modal
        :open="$showAgreementModal"
        :editing="$editingAgreementId !== null"
        :warning="$showAgreementModal ? $this->agreementStatusWarning : null"
        :delete-blocker="$showAgreementModal ? $this->agreementDeleteBlocker : null"
        :locked-currency="$showAgreementModal && $this->agreementCurrencyLocked ? $agreementCurrency : null"
        :confirming-delete="$confirmingAgreementDelete"
        :donor-name="$showAgreementModal ? $this->agreementDonorName : null"
        :installments="$installments"
        :installments-total="$this->installmentsTotal"
        :installments-differ="$this->installmentsDifferFromBudget"
        :currency-code="$agreementCurrency"
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
        wire:model="showNegotiationModal"
        size="md"
        :title="__('proposals.add_negotiation_note')"
        :description="__('proposals.negotiation_note_help')"
        submit="saveNegotiationNote"
        close="closeNegotiationModal"
        required-note
        data-test="negotiation-modal"
    >
        @if ($showNegotiationModal)
            <div class="grid gap-y-4">
                <x-ui.form-textarea wire:model="negotiationText" :label="__('proposals.what_the_donor_asked_for')" required autofocus />
                <x-ui.form-file
                    wire:model="negotiationFile"
                    :label="__('proposals.attachment_optional')"
                    :accept="collect(FileType::values())->map(fn ($extension) => '.'.$extension)->implode(',')"
                />
            </div>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" wire:loading.attr="disabled" wire:target="negotiationFile,saveNegotiationNote" data-test="save-negotiation-note-button">
                {{ __('proposals.add_negotiation_note') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.modal>

    <x-ui.modal
        wire:model="showGrantDatesModal"
        size="md"
        :title="__('proposals.set_grant_dates')"
        :description="__('proposals.grant_dates_help')"
        submit="saveGrantDates"
        close="closeGrantDatesModal"
        required-note
        data-test="grant-dates-modal"
    >
        @if ($showGrantDatesModal)
            <div class="grid gap-y-4 @md/modal:grid-cols-2 @md/modal:gap-x-4">
                <x-ui.form-input wire:model="grantDateBegin" :label="__('proposals.grant_dates_begin')" type="date" required :disabled="$this->proposal->actual_date_begin !== null" data-test="grant-date-begin" />
                <x-ui.form-input wire:model="grantDateEnd" :label="__('proposals.grant_dates_end')" type="date" required data-test="grant-date-end" />
            </div>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" wire:loading.attr="disabled" wire:target="saveGrantDates" data-test="save-grant-dates">{{ __('proposals.set_grant_dates') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.modal>

    <x-ui.modal
        wire:model="showExtensionModal"
        size="md"
        :title="__('proposals.extend_proposal')"
        :description="__('proposals.extend_help')"
        submit="saveExtension"
        close="closeExtensionModal"
        required-note
        data-test="extension-modal"
    >
        @if ($showExtensionModal)
            <div class="grid gap-y-4">
                <x-ui.form-input wire:model="extensionDateEnd" :label="__('proposals.extend_new_end')" type="date" :min="$this->proposal->effectiveDateEnd()?->addDay()->toDateString()" required autofocus data-test="extension-date-end" />
                <x-ui.form-textarea wire:model="extensionReason" :label="__('proposals.extend_reason')" rows="3" required data-test="extension-reason" />
                <x-ui.form-file
                    wire:model="extensionFile"
                    :label="__('proposals.extend_amendment')"
                    :accept="collect(FileType::values())->map(fn ($extension) => '.'.$extension)->implode(',')"
                />
                <p class="text-sm text-secondary-dark dark:text-ink" data-test="extension-consequence">{{ __('proposals.extend_confirm') }}</p>
            </div>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" wire:loading.attr="disabled" wire:target="extensionFile,saveExtension" data-test="save-extension-button">
                {{ __('proposals.extend_proposal') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.modal>

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

