{{--
    The add/edit proposal modal of proposals/index. Its body renders only while
    the modal is open. Included by ⚡index.blade.php.
--}}
@use('App\Enums\ProposalStatus')
    <x-ui.modal
        wire:model="showProposalModal"
        size="xl"
        :title="$editingProposalId === null ? __('proposals.add_proposal') : __('proposals.edit_proposal')"
        :description="__('proposals.phase_follows_automatically_from_the_status_you_choose')"
        submit="saveProposal"
        close="closeProposalModal"
        required-note
    >
        @if ($showProposalModal)
            <x-ui.form-section
                :title="__('common.section_details')"
                :hint="$editingProposalId === null ? __('proposals.the_starting_status_opens_the_status_history') : __('proposals.status_moves_only_through_change_status')"
                :fields="['name', 'internalProjectNumber', 'proposalStatus', 'notes']"
            >
                <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                    <x-ui.form-input wire:model="name" :label="__('proposals.proposal_name')" maxlength="255" required autofocus :class="$editingProposalId !== null ? '@2xl/modal:col-span-2' : null" />

                    @if ($editingProposalId === null)
                        <x-ui.form-select wire:model="proposalStatus" :label="__('proposals.starting_status')" required>
                            @foreach (ProposalStatus::cases() as $statusCase)
                                <option value="{{ $statusCase->value }}">{{ $statusCase->label() }} — {{ $statusCase->phase()->label() }}</option>
                            @endforeach
                        </x-ui.form-select>
                    @endif

                    <x-ui.form-input wire:model="internalProjectNumber" :label="__('common.internal_project_number')" maxlength="50" dir="ltr" required class="@2xl/modal:col-span-2" />

                    <x-ui.form-textarea wire:model="notes" :label="__('common.notes')" rows="2" class="@2xl/modal:col-span-2" />
                </div>
            </x-ui.form-section>

            <x-ui.form-section :title="__('common.section_schedule')" :fields="['plannedDateBegin', 'plannedDateEnd', 'actualDateBegin', 'actualDateEnd']">
                <div class="grid grid-cols-2 gap-x-4 gap-y-3 @4xl/modal:grid-cols-4">
                    <x-ui.form-input wire:model="plannedDateBegin" :label="__('proposals.planned_start')" type="date" required />
                    <x-ui.form-input wire:model="plannedDateEnd" :label="__('proposals.planned_end')" type="date" required />
                    <x-ui.form-input wire:model="actualDateBegin" :label="__('proposals.actual_start')" type="date" />
                    <x-ui.form-input wire:model="actualDateEnd" :label="__('proposals.actual_end')" type="date" />
                </div>
            </x-ui.form-section>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="save-proposal-button">
                {{ $editingProposalId === null ? __('proposals.create_proposal') : __('common.save_changes') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.modal>
