{{--
    The proposal form (x-proposals.form-modal) fed from ManagesProposalForm. Included by ⚡index.blade.php
    and _show-modals.blade.php, so both pages pass it the same readings.
--}}
@use('App\Enums\ProposalStatus')
    <x-proposals.form-modal
        :open="$showProposalModal"
        :editing="$editingProposalId !== null"
        :donors="$showProposalModal ? $this->proposalDonors : []"
        :donor-pending="$showProposalModal && $this->proposalDonorIsPending"
        :can-add-donor="auth()->user()->can('donors.create')"
        :locations="$locations"
        :cities="$showProposalModal ? $this->locationCityOptions : []"
        :programs="$showProposalModal ? $this->locationProgramOptions : []"
        :centers-by-city="$showProposalModal ? $this->locationCentersByCity : []"
        :chosen-projects="$this->proposalChosenProjects"
        :linked-projects="$this->proposalLinkedProjects"
        :shows-money="$showProposalModal && $this->proposalFormShowsMoney"
        :locked-donor-name="$this->proposalEditFacts['donorLocked'] ? $this->proposalDonors->firstWhere('id', (int) $donorId)?->name : null"
        :needs-beneficiaries-reason="$this->proposalEditFacts['approved']"
        :skipping="$startingStage === ProposalStatus::ProposalDraft->value"
        :renewable="$this->renewableProposals"
        :renewal="$this->renewalPreview"
        :kind="$kind"
        :stored-number="$editingProposalId !== null ? $internalProjectNumber : null"
    />

@can('donors.create')
    <livewire:donors.request-form />
@endcan
