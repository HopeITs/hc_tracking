{{--
    The change-status modal of proposals/index. Its body renders only while
    the modal is open. Included by ⚡index.blade.php.
--}}
@use('App\Enums\ProposalStatus')
    <x-ui.modal
        wire:model="showStageModal"
        size="md"
        :title="__('proposals.change_status')"
        :description="__('proposals.the_new_status_and_your_reason_are_recorded_together_as_one')"
        submit="recordStageChange"
        close="closeStageModal"
        required-note
    >
        @if ($showStageModal)
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
            <x-ui.button type="submit" tone="primary" data-test="save-stage-button">{{ __('proposals.record_change') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.modal>
