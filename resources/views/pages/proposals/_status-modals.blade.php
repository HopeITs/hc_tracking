{{--
    The Change Status and Correct Status dialogs, shared by proposals/index and
    proposals/show (ManagesProposalStatus). The body of each renders only while
    its modal is open. Only the targets of the transition table are offered and
    only the fields of the chosen target appear.
--}}
    <x-ui.modal
        wire:model="showStatusModal"
        size="md"
        :title="__('proposals.change_status')"
        :description="__('proposals.the_new_status_and_your_reason_are_recorded_together_as_one')"
        submit="changeStatus"
        close="closeStatusModal"
        required-note
        data-test="status-modal"
    >
        @if ($showStatusModal)
            @php
                $chosenTarget = \App\Enums\ProposalStatus::tryFrom($newStatus);
                $statusFrom = \App\Enums\ProposalStatus::tryFrom($statusExpectedFrom);
                $targetDescriptions = [
                    'concept note review' => __('proposals.target_concept_note_review'),
                    'concept note' => __('proposals.target_concept_note'),
                    'concept note submitted' => __('proposals.target_concept_note_submitted'),
                    'proposal draft' => __('proposals.target_proposal_draft'),
                    'proposal review' => __('proposals.target_proposal_review'),
                    'submitted' => __('proposals.target_submitted'),
                    'on hold' => __('proposals.target_on_hold'),
                    'rejected' => __('proposals.target_rejected'),
                    'completed' => __('proposals.target_completed'),
                ];
            @endphp

            @if ($statusStale)
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-primary bg-primary/5 p-3 text-sm text-status-critical" role="alert" data-test="status-stale-alert">
                    <p class="min-w-0 flex-1 font-semibold">{{ __('proposals.stale_status_explained') }}</p>
                    <x-ui.button type="button" size="sm" icon="arrow-path" wire:click="reloadStatusModal" wire:loading.attr="disabled" wire:target="reloadStatusModal" data-test="status-reload-button">{{ __('proposals.reload') }}</x-ui.button>
                </div>
            @endif

            @if ($errors->has('statusGate'))
                <div class="mb-4 rounded-lg border border-primary bg-primary/5 p-3 text-sm text-status-critical" role="alert" data-test="status-gate-alert">
                    <ul class="list-disc ps-5">
                        @foreach ($errors->get('statusGate') as $gateMessage)
                            <li>{{ $gateMessage }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($this->statusTargets === [])
                <x-ui.empty-state icon="arrows-right-left" :heading="__('proposals.no_status_moves_from_here')" :body="$statusFrom === \App\Enums\ProposalStatus::Approved ? __('proposals.approved_moves_on_with_the_report_template') : __('proposals.no_status_moves_from_here_body')" />
            @else
                <div class="grid grid-cols-2 gap-x-4 gap-y-4">
                    <div class="col-span-2">
                        <x-ui.form-select wire:model.live="newStatus" :label="__('proposals.new_status')" :placeholder="__('proposals.choose_the_new_status')" required autofocus>
                            @foreach ($this->statusTargets as $targetCase)
                                <option value="{{ $targetCase->value }}">{{ $targetCase->label() }} — {{ $targetCase->phase()->label() }}</option>
                            @endforeach
                        </x-ui.form-select>
                        @if ($chosenTarget !== null)
                            <p class="mt-1.5 text-xs text-secondary-medium dark:text-ink-muted" data-test="status-target-description">{{ $targetDescriptions[$chosenTarget->value] ?? '' }}</p>
                        @endif
                        @if ($statusFrom === \App\Enums\ProposalStatus::Submitted || $statusFrom === \App\Enums\ProposalStatus::OnHold)
                            <p class="mt-1.5 text-xs text-secondary-medium dark:text-ink-muted" data-test="status-approve-pointer">{{ __('proposals.approval_has_its_own_step') }}</p>
                        @endif
                    </div>

                    @if (in_array('effective_date', $this->statusFields, true))
                        <x-ui.form-input wire:model="statusDate" :label="__('proposals.effective_date')" type="date" :max="now()->toDateString()" required />
                    @endif

                    @if (in_array('remind_on', $this->statusFields, true))
                        <x-ui.form-input wire:model="statusRemindOn" :label="__('proposals.remind_me_on')" type="date" :min="now()->addDay()->toDateString()" :help="__('proposals.remind_me_on_help')" required />
                    @endif

                    @if (in_array('reason', $this->statusFields, true))
                        <x-ui.form-textarea wire:model="statusContext" :label="__('proposals.why_did_it_change')" required class="col-span-2" />
                    @endif
                </div>
            @endif
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" wire:loading.attr="disabled" wire:target="changeStatus" :disabled="$this->statusTargets === [] || $newStatus === ''" data-test="save-status-button">
                {{ __('proposals.record_change') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.modal>

    <x-ui.confirm-modal
        wire:model="showCorrectModal"
        close="closeCorrectModal"
        :title="__('proposals.correct_status')"
        :message="__('proposals.correct_status_consequence')"
        confirm="correctStatus"
        :confirm-label="__('proposals.correct_status')"
        confirm-test="confirm-correct-status-button"
        tone="neutral"
    >
        @if ($showCorrectModal)
            @if ($statusStale)
                <div class="mb-4 rounded-lg border border-primary bg-primary/5 p-3 text-sm text-status-critical" role="alert" data-test="correct-stale-alert">
                    <p class="font-semibold">{{ __('proposals.stale_status_explained') }}</p>
                </div>
            @endif

            <div class="grid gap-4">
                <x-ui.form-select wire:model="correctTarget" :label="__('proposals.correct_to')" :placeholder="__('proposals.choose_the_new_status')" required>
                    @foreach ($this->correctionTargets as $correctionCase)
                        <option value="{{ $correctionCase->value }}">{{ $correctionCase->label() }} — {{ $correctionCase->phase()->label() }}</option>
                    @endforeach
                </x-ui.form-select>

                <x-ui.form-textarea wire:model="correctReason" :label="__('proposals.why_correct_the_status')" required />
            </div>
        @endif
    </x-ui.confirm-modal>
