{{--
    The Approve Proposal dialog of proposals/show (ManagesProposalApproval).
    The donor's decision, the grant dates and the evidence of the approval go
    to ApproveProposal; the preview shows what each linked project will do.
    The body renders only while the dialog is open.
--}}
    <x-ui.modal
        wire:model="showApproveModal"
        size="md"
        :title="__('proposals.approve_proposal_title')"
        :description="__('proposals.approval_confirm_consequence')"
        submit="approveProposal"
        close="closeApproveModal"
        required-note
        data-test="approve-modal"
    >
        @if ($showApproveModal)
            @if ($errors->has('approveGate'))
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-primary bg-secondary-soft/60 p-3 text-sm text-status-critical" role="alert" data-test="approve-gate-alert">
                    <ul class="min-w-0 flex-1 list-disc ps-5 font-semibold">
                        @foreach ($errors->get('approveGate') as $gateMessage)
                            <li>{{ $gateMessage }}</li>
                        @endforeach
                    </ul>
                    <x-ui.button type="button" size="sm" icon="arrow-path" wire:click="reloadApproveModal" wire:loading.attr="disabled" wire:target="reloadApproveModal" data-test="approve-reload-button">{{ __('proposals.reload') }}</x-ui.button>
                </div>
            @endif

            <div class="grid grid-cols-2 gap-x-4 gap-y-4">
                <x-ui.form-input wire:model="approveDecisionDate" :label="__('proposals.decision_date')" type="date" :max="now()->toDateString()" required class="col-span-2" data-test="approve-decision-date" />

                <x-ui.form-input wire:model="approveBegin" :label="__('proposals.grant_start')" type="date" required :help="__('proposals.grant_start_help')" class="col-span-2 sm:col-span-1" data-test="approve-begin" />

                <x-ui.form-input wire:model="approveEnd" :label="__('proposals.grant_end')" type="date" required class="col-span-2 sm:col-span-1" data-test="approve-end" />

                <x-ui.form-textarea wire:model="approveNote" :label="__('proposals.approval_decision_note')" class="col-span-2" rows="2" />

                <div class="col-span-2 grid gap-3" data-test="approve-evidence">
                    <x-ui.form-segmented
                        wire:model.live="approveEvidenceMode"
                        :label="__('proposals.approval_evidence')"
                        required
                        :options="[
                            ['value' => 'file', 'label' => __('proposals.approval_evidence_file'), 'icon' => 'paper-clip', 'test' => 'approve-evidence-file'],
                            ['value' => 'note', 'label' => __('proposals.approval_evidence_note'), 'icon' => 'pencil-square', 'test' => 'approve-evidence-note'],
                        ]"
                    />

                    @if ($approveEvidenceMode === 'file')
                        <x-ui.form-file
                            wire:model="approveFile"
                            :label="__('proposals.approval_file')"
                            :accept="collect(\App\Enums\FileType::values())->map(fn ($extension) => '.'.$extension)->implode(',')"
                        />
                        @error('approveFile')
                            <p class="text-sm text-status-critical" role="alert" data-test="approve-file-error">{{ $message }}</p>
                        @enderror
                    @else
                        <x-ui.form-textarea wire:model="approveEvidenceNote" :label="__('proposals.approval_note_label')" :help="__('proposals.approval_note_help')" rows="3" />
                    @endif
                </div>

                <div class="col-span-2" data-test="approve-preview">
                    <p class="type-label mb-2 text-secondary-medium dark:text-ink-muted">{{ __('proposals.approval_preview') }}</p>

                    @php($locationPlan = $this->approvalLocations)
                    @php($hasLocationPlan = $locationPlan['create'] + $locationPlan['link'] + $locationPlan['wait'] > 0)

                    @if ($hasLocationPlan)
                        <div class="mb-3 rounded-lg border border-board-line p-3 text-sm" data-test="approve-locations-preview">
                            <p class="font-semibold text-secondary-dark dark:text-ink">{{ __('proposals.approval_locations_title') }}</p>
                            <ul class="mt-1.5 grid gap-1 text-secondary-dark dark:text-ink">
                                <li class="flex items-center gap-2" data-test="approve-locations-create"><flux:icon.plus-circle variant="micro" class="size-4 shrink-0" aria-hidden="true" />{{ trans_choice('proposals.approval_locations_create', $locationPlan['create'], ['count' => $locationPlan['create']]) }}</li>
                                <li class="flex items-center gap-2" data-test="approve-locations-link"><flux:icon.link variant="micro" class="size-4 shrink-0" aria-hidden="true" />{{ trans_choice('proposals.approval_locations_link', $locationPlan['link'], ['count' => $locationPlan['link']]) }}</li>
                                <li class="flex items-center gap-2" data-test="approve-locations-wait"><flux:icon.clock variant="micro" class="size-4 shrink-0" aria-hidden="true" />{{ trans_choice('proposals.approval_locations_wait', $locationPlan['wait'], ['count' => $locationPlan['wait']]) }}</li>
                            </ul>
                            @foreach ($locationPlan['warnings'] as $locationWarning)
                                <p class="mt-2 flex items-start gap-2 rounded-md bg-status-warn-soft px-2 py-1.5 text-status-warn" role="status" data-test="approve-locations-warning-{{ $locationWarning }}">
                                    <flux:icon.exclamation-triangle variant="micro" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                                    {{ __('proposals.approval_warning_'.$locationWarning) }}
                                </p>
                            @endforeach
                        </div>
                    @endif

                    @if ($this->approvalPreview === [] && $locationPlan['create'] + $locationPlan['link'] === 0)
                        <p class="text-sm text-status-critical" data-test="approve-no-projects">{{ __('proposals.link_project_first') }}</p>
                    @elseif ($this->approvalPreview !== [])
                        <ul class="divide-y divide-board-line rounded-lg border border-board-line">
                            @foreach ($this->approvalPreview as $previewProjectId => $previewProject)
                                <li class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm" data-test="approve-preview-{{ $previewProject['outcome'] }}">
                                    <span class="min-w-0 flex-1 font-medium text-secondary-dark dark:text-ink" dir="auto">{{ $previewProject['name'] }}</span>
                                    <span @class([
                                        'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-bold whitespace-nowrap',
                                        'bg-status-good-soft text-status-good' => $previewProject['outcome'] === 'funded',
                                        'bg-status-warn-soft text-status-warn' => $previewProject['outcome'] === 'waiting',
                                        'bg-secondary-soft text-secondary-dark dark:bg-surface-2 dark:text-ink' => in_array($previewProject['outcome'], ['extended', 'skipped'], true),
                                    ])>
                                        <flux:icon :name="match ($previewProject['outcome']) { 'funded' => 'check-circle', 'waiting' => 'clock', 'extended' => 'arrows-right-left', default => 'minus-circle' }" variant="micro" class="size-3 shrink-0" aria-hidden="true" />
                                        {{ match ($previewProject['outcome']) {
                                            'funded' => __('proposals.approval_outcome_funded'),
                                            'waiting' => __('proposals.approval_outcome_waiting'),
                                            'extended' => __('proposals.approval_outcome_extended'),
                                            default => __('proposals.approval_outcome_skipped'),
                                        } }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" wire:loading.attr="disabled" wire:target="approveProposal,approveFile" :disabled="! $showApproveModal || ($this->approvalPreview === [] && $this->approvalLocations['create'] + $this->approvalLocations['link'] === 0)" data-test="confirm-approve-button">
                {{ __('proposals.approve_proposal') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.modal>
