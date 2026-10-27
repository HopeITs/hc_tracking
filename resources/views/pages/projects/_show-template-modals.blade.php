{{-- The "Report template" tab's dialogs (T33 slice 3), rendered only for the template's managers. --}}
<x-ui.modal
    wire:model="showTemplateLineModal"
    size="md"
    :title="__('report_templates.tab.edit_title')"
    :description="__('report_templates.tab.edit_description')"
    submit="saveTemplateLine"
    close="closeTemplateLineModal"
    required-note
>
    @if ($showTemplateLineModal)
        <div class="grid gap-4" data-test="template-line-form">
            <x-ui.form-select wire:model.live="templateLineSchedule" :label="__('report_templates.tab.field_schedule')" required data-test="template-line-schedule">
                @foreach ($this->templateScheduleOptions() as $option)
                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                @endforeach
            </x-ui.form-select>

            @if ($templateLineSchedule === \App\Enums\LineSchedule::SelectedMonths->value)
                <x-ui.form-multiselect wire:model="templateLineMonths" :label="__('report_templates.tab.field_months')" :placeholder="__('common.select')" :help="__('report_templates.tab.field_months_help')" required data-test="template-line-months">
                    @foreach (range(1, 12) as $month)
                        <option value="{{ $month }}">{{ \Carbon\CarbonImmutable::create(2000, $month, 1)->translatedFormat('F') }}</option>
                    @endforeach
                </x-ui.form-multiselect>
            @elseif ($templateLineSchedule === \App\Enums\LineSchedule::OnceAfterMonths->value)
                <x-ui.form-input type="number" min="1" max="120" step="1" wire:model="templateLineOnceAfter" :label="__('report_templates.tab.field_once_after')" :help="__('report_templates.tab.field_once_after_help')" required data-test="template-line-once-after" />
            @endif

            <x-ui.form-select wire:model="templateLineEnteredBy" :label="__('report_templates.tab.field_entered_by')" required data-test="template-line-entered-by">
                @foreach ($this->templateCollectorOptions() as $option)
                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                @endforeach
            </x-ui.form-select>

            <x-ui.form-multiselect wire:model="templateLineEvidenceTypeIds" :label="__('report_templates.tab.field_evidence')" :placeholder="__('common.select')" :help="__('report_templates.tab.field_evidence_help')" data-test="template-line-evidence">
                @foreach ($this->templateEvidenceTypeOptions as $evidenceType)
                    <option value="{{ $evidenceType->id }}">{{ $evidenceType->localizedName() }}</option>
                @endforeach
            </x-ui.form-multiselect>

            <x-ui.form-input type="number" min="1" step="1" wire:model="templateLineFixedDenominator" :label="__('report_templates.tab.field_fixed_denominator')" :help="__('report_templates.tab.field_fixed_denominator_help')" data-test="template-line-fixed-denominator" />
        </div>
    @endif

    <x-slot:actions>
        <x-ui.button type="submit" tone="primary" wire:target="saveTemplateLine" wire:loading.attr="disabled" data-test="template-line-save">{{ __('report_templates.tab.save') }}</x-ui.button>
    </x-slot:actions>
</x-ui.modal>

<x-ui.confirm-modal
    wire:model="showTemplateConfirmModal"
    tone="neutral"
    :title="__('report_templates.tab.confirm_title')"
    :message="$this->templateConfirmMessage()"
    confirm="confirmReportTemplate"
    :confirm-label="__('report_templates.tab.confirm_selected')"
    confirm-test="template-confirm"
/>

<x-ui.confirm-modal
    wire:model="showRetireTemplateLineModal"
    :title="__('report_templates.tab.retire_title')"
    :message="__('report_templates.tab.retire_body')"
    confirm="retireTemplateLine"
    :confirm-label="__('report_templates.tab.retire')"
    confirm-test="template-retire"
/>
