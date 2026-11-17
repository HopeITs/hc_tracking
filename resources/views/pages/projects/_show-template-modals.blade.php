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
    icon="clipboard-document-check"
    wire:model="showTemplateConfirmModal"
    tone="neutral"
    :title="__('report_templates.tab.confirm_title')"
    :message="$this->templateConfirmMessage()"
    confirm="confirmReportTemplate"
    :confirm-label="__('report_templates.tab.confirm_selected')"
    confirm-test="template-confirm"
/>

<x-ui.confirm-modal
    icon="archive-box"
    wire:model="showRetireTemplateLineModal"
    :title="__('report_templates.tab.retire_title')"
    :message="__('report_templates.tab.retire_body')"
    confirm="retireTemplateLine"
    :confirm-label="__('report_templates.tab.retire')"
    confirm-test="template-retire"
/>

{{-- The narrative question dialogs (Y-07 slice 2), mirroring the bank's page: add / edit, switch off, delete. --}}
<x-ui.modal
    wire:model="showTemplateQuestionModal"
    size="sm"
    :title="$editingTemplateQuestionId === null ? __('narrative_questions.add_question') : __('narrative_questions.edit_question')"
    :description="__('report_templates.tab.question_dialog_description')"
    submit="saveTemplateQuestion"
    close="closeTemplateQuestionModal"
    required-note
>
    @if ($showTemplateQuestionModal)
        @php
            $chosenQuestionFrequency = \App\Enums\NarrativeQuestionFrequency::tryFrom($templateQuestionFrequency);
        @endphp
        <div class="grid gap-y-3" data-test="template-question-form">
            <x-ui.form-textarea wire:model="templateQuestionTextAr" :label="__('narrative_questions.fields.text_ar')" required maxlength="1000" dir="rtl" lang="ar" data-test="template-question-text-ar" />
            <x-ui.form-textarea wire:model="templateQuestionTextEn" :label="__('narrative_questions.fields.text_en')" :help="__('narrative_questions.text_en_help')" maxlength="1000" dir="ltr" lang="en" data-test="template-question-text-en" />
            <x-ui.form-segmented
                wire:model.live="templateQuestionFrequency"
                :label="__('narrative_questions.fields.frequency')"
                :help="$chosenQuestionFrequency === null ? null : __('narrative_questions.frequency_help.'.$chosenQuestionFrequency->value)"
                required
                :options="collect(\App\Enums\NarrativeQuestionFrequency::cases())->map(fn (\App\Enums\NarrativeQuestionFrequency $case): array => ['value' => $case->value, 'label' => $case->label(), 'test' => 'template-question-frequency-'.$case->value])->all()"
            />
        </div>
    @endif

    <x-slot:actions>
        <x-ui.button type="submit" tone="primary" wire:target="saveTemplateQuestion" wire:loading.attr="disabled" data-test="template-question-save">{{ __('common.save_changes') }}</x-ui.button>
    </x-slot:actions>
</x-ui.modal>

<x-ui.confirm-modal
    icon="no-symbol"
    wire:model="showSwitchOffTemplateQuestionModal"
    close="cancelSwitchOffTemplateQuestion"
    tone="neutral"
    :title="__('narrative_questions.deactivate_title', ['name' => $switchingOffTemplateQuestionName])"
    :message="__('report_templates.tab.question_switch_off_message')"
    confirm="switchOffTemplateQuestion"
    :confirm-label="__('narrative_questions.deactivate')"
    confirm-test="confirm-switch-off-template-question"
/>

{{-- One dialog whose text follows $deletingTemplateQuestionHasAnswers: two dialogs swapped behind an @if would replace the open <dialog> element mid-request. --}}
<x-ui.confirm-modal
    :icon="$deletingTemplateQuestionHasAnswers ? 'no-symbol' : 'trash'"
    :permanent="! $deletingTemplateQuestionHasAnswers"
    wire:model="showDeleteTemplateQuestionModal"
    close="cancelDeleteTemplateQuestion"
    :tone="$deletingTemplateQuestionHasAnswers ? 'neutral' : 'danger'"
    :title="__('narrative_questions.delete_title', ['name' => $deletingTemplateQuestionName])"
    :message="$deletingTemplateQuestionHasAnswers ? __('report_templates.question_has_answers') : __('report_templates.tab.question_delete_message')"
    :confirm="$deletingTemplateQuestionHasAnswers ? 'switchOffInsteadOfDeleteTemplateQuestion' : 'deleteTemplateQuestion'"
    :confirm-label="$deletingTemplateQuestionHasAnswers ? __('narrative_questions.deactivate_instead') : __('narrative_questions.delete')"
    :confirm-test="$deletingTemplateQuestionHasAnswers ? 'confirm-switch-off-instead-template-question' : 'confirm-delete-template-question'"
/>
