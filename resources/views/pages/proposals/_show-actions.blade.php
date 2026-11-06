{{-- The proposal's own actions (status, approve, edit, agreement, correct), shared by the standalone page head and the Story's embedded head. --}}
<div class="flex flex-wrap items-center gap-2 @3xl/detail:shrink-0 @3xl/detail:flex-nowrap">
    <x-ui.enum-badge
        :case="$this->proposal->status"
        :label="$this->proposal->statusLabel()"
        :tone="$this->proposal->isOnHold() ? \App\Enums\StatusTone::Warn : null"
    />
    <span class="rounded-md border border-secondary-light px-2 py-0.5 text-[11px] font-semibold text-secondary-medium dark:text-ink-muted">
        {{ $this->proposal->phase->label() }}
    </span>
    @can('update', $this->proposal)
    <x-ui.button size="sm" icon="pencil-square" class="min-h-10 touch-manipulation" wire:click="openEditModal({{ $this->proposal->id }})" data-test="open-details-modal">{{ __('proposals.edit_details') }}</x-ui.button>
    @can('approve', $this->proposal)
    @if ($this->proposal->canBeApproved())
    <x-ui.button tone="primary" size="sm" icon="check-badge" class="min-h-10 touch-manipulation" wire:click="openApproveModal({{ $this->proposal->id }})" data-test="open-approve-modal">{{ __('proposals.approve_proposal') }}</x-ui.button>
    @endif
    @endcan
    @if ($this->proposal->transitionTargets() !== [])
    <x-ui.button tone="primary" size="sm" icon="arrow-path" class="min-h-10 touch-manipulation" wire:click="openStatusModal({{ $this->proposal->id }})" data-test="open-status-modal">{{ __('proposals.change_status') }}</x-ui.button>
    @endif
    @if ($this->proposal->status === \App\Enums\ProposalStatus::Submitted)
    <x-ui.button size="sm" icon="chat-bubble-left-ellipsis" class="min-h-10 touch-manipulation" wire:click="openNegotiationModal" data-test="add-negotiation-note">{{ __('proposals.add_negotiation_note') }}</x-ui.button>
    @endif
    {{-- A hold is a flag, not a move (W-06, D-58): its own two actions, offered
         only where contracts.md §1 allows a hold. --}}
    @if ($this->proposal->isOnHold())
    <x-ui.button size="sm" icon="play-circle" class="min-h-10 touch-manipulation" wire:click="openOffHoldModal" data-test="open-off-hold-modal">{{ __('proposals.take_off_hold') }}</x-ui.button>
    @elseif (in_array($this->proposal->status, [\App\Enums\ProposalStatus::Submitted, \App\Enums\ProposalStatus::ConceptNoteSubmitted], true))
    <x-ui.button size="sm" icon="pause-circle" class="min-h-10 touch-manipulation" wire:click="openHoldModal" data-test="open-hold-modal">{{ __('proposals.put_on_hold') }}</x-ui.button>
    @endif
    {{-- Older grants were approved without their actual dates; donor reports need them (SetGrantDates). --}}
    @if ($this->proposal->status->isApprovedByDonor() && $this->proposal->lacksGrantDates())
    <x-ui.button tone="primary" size="sm" icon="calendar" class="min-h-10 touch-manipulation" wire:click="openGrantDatesModal" data-test="open-grant-dates-modal">{{ __('proposals.set_grant_dates') }}</x-ui.button>
    @endif
    @if ($this->proposal->status === \App\Enums\ProposalStatus::Active)
    <x-ui.button size="sm" icon="calendar-days" class="min-h-10 touch-manipulation" wire:click="openExtensionModal" data-test="open-extension-modal">{{ __('proposals.extend_proposal') }}</x-ui.button>
    @endif
    @if ($this->canRecordFinalReportAcceptance)
    <x-ui.button tone="primary" size="sm" icon="document-check" class="min-h-10 touch-manipulation" wire:click="openAcceptanceModal" data-test="open-final-report-modal">{{ __('proposals.record_final_report_acceptance') }}</x-ui.button>
    @endif
    @endcan
    @if ($this->canRecordAgreement)
    <x-ui.button size="sm" icon="banknotes" class="min-h-10 touch-manipulation" wire:click="openAgreementCreate" data-test="record-agreement-button">{{ __('funding_agreements.record_agreement') }}</x-ui.button>
    @endif
    @can('correctStatus', $this->proposal)
    @if ($this->proposal->status->isTerminal())
    <x-ui.button size="sm" icon="arrow-uturn-left" class="min-h-10 touch-manipulation" wire:click="openCorrectModal({{ $this->proposal->id }})" data-test="open-correct-status-modal">{{ __('proposals.correct_status') }}</x-ui.button>
    @endif
    @endcan
</div>
