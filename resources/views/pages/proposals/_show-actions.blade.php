{{-- The proposal's own actions (status, approve, edit, agreement, correct), shared by the standalone page head and the Story's embedded head. --}}
<div class="flex flex-wrap items-center gap-2 @3xl/detail:shrink-0 @3xl/detail:flex-nowrap">
    <x-ui.enum-badge :case="$this->proposal->status" />
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
