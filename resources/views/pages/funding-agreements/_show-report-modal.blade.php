{{--
    The add/edit donor report modal of funding-agreements/show. Its body
    renders only while the modal is open. Included by ⚡show.blade.php.
--}}
@use('App\Enums\DonorReportType')
    <x-ui.modal
        wire:model="showReportModal"
        size="md"
        :title="$editingReportId !== null ? __('funding_agreements.edit_report') : __('funding_agreements.add_report')"
        :description="__('funding_agreements.a_report_the_donor_expects_under_this_agreement')"
        submit="saveReport"
        close="closeReportModal"
        required-note
    >
        @if ($showReportModal)
            <div class="grid grid-cols-2 gap-x-4 gap-y-4">
                <x-ui.form-input wire:model="reportTitle" :label="__('funding_agreements.report_title')" maxlength="255" required autofocus class="col-span-2" />

                <x-ui.form-segmented
                    wire:model="reportType"
                    :label="__('common.type')"
                    required
                    class="col-span-2"
                    :options="collect(DonorReportType::cases())->map(fn (DonorReportType $reportTypeCase): array => ['value' => $reportTypeCase->value, 'label' => $reportTypeCase->label()])->all()"
                />

                <x-ui.form-input wire:model="reportDueOn" :label="__('funding_agreements.due')" type="date" required class="col-span-2" />
            </div>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="save-report-button">
                {{ $editingReportId !== null ? __('common.save_changes') : __('funding_agreements.add_report') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.modal>
