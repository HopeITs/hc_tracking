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
        :linked-projects="$this->proposalLinkedProjects"
        :shows-money="$showProposalModal && $this->proposalFormShowsMoney"
        :locked-donor-name="$this->proposalEditFacts['donorLocked'] ? $this->proposalDonors->firstWhere('id', (int) $donorId)?->name : null"
        :needs-beneficiaries-reason="$this->proposalEditFacts['approved']"
        :skipping="$startingStage === ProposalStatus::ProposalDraft->value"
        :renewable="$this->renewableProposals"
        :renewal="$this->renewalPreview"
        :kind="$kind"
        :stored-number="$editingProposalId !== null ? $internalProjectNumber : null"
        {{-- Y-09: only proposals/show offers a change request (RequestsProposalChange). --}}
        :change-request="$changeRequestMode ?? false"
        :change-summary="$changeSummary ?? null"
    />

    {{-- Y-10: a saved location row with targets asks before it leaves the form. --}}
    <x-ui.confirm-modal
        icon="flag"
        wire:model="showLocationTargetsModal"
        :tone="$this->removingLocationDeletesTargets ? 'danger' : 'neutral'"
        close="closeLocationTargetsModal"
        :title="__('proposals.location_targets_title')"
        :confirm="$this->removingLocationDeletesTargets ? 'clearRemovingLocationTargets' : 'closeLocationTargetsModal'"
        :confirm-label="$this->removingLocationDeletesTargets ? __('proposals.delete_targets_and_remove') : __('proposals.keep_location')"
        confirm-test="confirm-location-targets"
        data-test="location-targets-modal"
    >
        @if ($showLocationTargetsModal && $editingProposalId !== null)
            <x-proposals.targets-notice
                :body="trans_choice('proposals.location_targets_body', $removingLocationTargets['count'], ['count' => $removingLocationTargets['count']])"
                :reported="$removingLocationTargets['reported'] > 0 ? trans_choice('proposals.location_targets_reported', $removingLocationTargets['reported'], ['count' => $removingLocationTargets['reported']]) : null"
                :no-right="$removingLocationTargets['reported'] === 0 && ! $this->removingLocationDeletesTargets ? __('proposals.location_targets_no_right') : null"
                :url="$this->locationTargetsUrl"
            />
            @if ($this->removingLocationDeletesTargets)
                <p class="mt-3 text-sm text-secondary-medium dark:text-ink-muted">{{ __('proposals.location_targets_then_save') }}</p>
            @endif
            <flux:error name="targets" />
        @endif
    </x-ui.confirm-modal>

@can('donors.create')
    <livewire:donors.request-form />
@endcan
