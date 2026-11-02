{{--
    The Record Final Report Acceptance dialog of proposals/show
    (ManagesFinalReportAcceptance, W-05). The acceptance date and the optional
    acceptance email go to RecordFinalReportAcceptance. The body renders only
    while the dialog is open.
--}}
    <x-ui.modal
        wire:model="showAcceptanceModal"
        size="md"
        :title="__('proposals.final_report_acceptance_title')"
        :description="__('proposals.final_report_acceptance_help')"
        submit="recordFinalReportAcceptance"
        close="closeAcceptanceModal"
        required-note
        data-test="final-report-modal"
    >
        @if ($showAcceptanceModal)
            @if ($errors->has('finalReportGate'))
                <div class="mb-4 rounded-lg border border-primary bg-secondary-soft/60 p-3 text-sm text-status-critical" role="alert" data-test="final-report-gate-alert">
                    <ul class="list-disc ps-5 font-semibold">
                        @foreach ($errors->get('finalReportGate') as $gateMessage)
                            <li>{{ $gateMessage }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="grid gap-y-4">
                <x-ui.form-input wire:model="finalReportAcceptedOn" :label="__('proposals.final_report_accepted_on')" type="date" :max="now()->toDateString()" required data-test="final-report-accepted-on" />

                <x-ui.form-file
                    wire:model="finalReportAcceptanceFile"
                    :label="__('proposals.final_report_acceptance_file')"
                    :accept="collect(\App\Enums\FileType::values())->map(fn ($extension) => '.'.$extension)->implode(',')"
                />
                @error('finalReportAcceptanceFile')
                    <p class="text-sm text-status-critical" role="alert" data-test="final-report-file-error">{{ $message }}</p>
                @enderror
            </div>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" wire:loading.attr="disabled" wire:target="recordFinalReportAcceptance,finalReportAcceptanceFile" data-test="confirm-final-report-button">
                {{ __('proposals.record_final_report_acceptance') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.modal>
