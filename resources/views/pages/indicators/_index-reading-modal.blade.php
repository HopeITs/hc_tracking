{{--
    The reading dialog of indicators/index: log or correct one dated
    reading. Included by ⚡index.blade.php.
--}}
    <x-ui.modal
        wire:model="showProgressModal"
        size="md"
        :title="$editingReadingId === null ? __('indicators.log_a_reading') : __('indicators.edit_reading')"
        :description="__('indicators.one_dated_number_with_the_evidence_it_came_from')"
        submit="saveProgress"
        close="closeProgressModal"
        required-note
    >
        @if ($showProgressModal)
            <div class="grid grid-cols-2 gap-x-4 gap-y-4">
                <x-ui.form-input wire:model="progressDate" :label="__('indicators.date')" type="date" :max="now()->toDateString()" required autofocus />
                <x-ui.form-input wire:model="progressValue" :label="__('indicators.value')" type="number" step="0.01" required />
                <x-ui.form-input wire:model="progressSourceOfVerification" :label="__('indicators.source_of_verification')" maxlength="255" class="col-span-2" />
                <x-ui.form-textarea wire:model="progressNotes" :label="__('common.notes')" class="col-span-2" />
            </div>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="save-progress-button">
                {{ $editingReadingId === null ? __('indicators.log_reading') : __('common.save_changes') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.modal>
