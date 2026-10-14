{{--
    The proposal form (x-proposals.form-modal) fed from ManagesProposalForm. Included by ⚡index.blade.php
    and _show-modals.blade.php, so both pages pass it the same readings.
--}}
@use('App\Enums\ProposalStatus')
    <x-proposals.form-modal
        :open="$showProposalModal"
        :editing="$editingProposalId !== null"
        :donors="$showProposalModal ? $this->proposalDonors : []"
        :picker-projects="$this->proposalPickerProjects"
        :chosen-projects="$this->proposalChosenProjects"
        :linked-projects="$this->proposalLinkedProjects"
        :shows-money="$showProposalModal && $this->proposalFormShowsMoney"
        :locked-donor-name="$this->proposalEditFacts['donorLocked'] ? $this->proposalDonors->firstWhere('id', (int) $donorId)?->name : null"
        :needs-beneficiaries-reason="$this->proposalEditFacts['approved']"
        :skipping="$startingStage === ProposalStatus::ProposalDraft->value"
        :stored-number="$editingProposalId !== null ? $internalProjectNumber : null"
    />
