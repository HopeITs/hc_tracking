{{--
    The create/edit dialog of admin/narrative-questions. Included by
    ⚡narrative-questions.blade.php. The two texts and the frequency are typed;
    position and the active flag have their own controls on the list.
--}}
@use('App\Enums\NarrativeQuestionFrequency')

<x-ui.modal
    wire:model="showQuestionModal"
    size="sm"
    :title="$editingQuestionId === null ? __('narrative_questions.add_question') : __('narrative_questions.edit_question')"
    :description="__('narrative_questions.dialog_description')"
    submit="saveQuestion"
    close="closeQuestionModal"
    required-note
>
    @if ($showQuestionModal)
        @php
            $chosenFrequency = NarrativeQuestionFrequency::tryFrom($questionFrequency);
        @endphp
        <div class="grid gap-y-3">
            <x-ui.form-textarea wire:model="textAr" :label="__('narrative_questions.fields.text_ar')" required maxlength="1000" dir="rtl" lang="ar" data-test="narrative-question-text-ar" />
            <x-ui.form-textarea wire:model="textEn" :label="__('narrative_questions.fields.text_en')" :help="__('narrative_questions.text_en_help')" maxlength="1000" dir="ltr" lang="en" data-test="narrative-question-text-en" />
            <x-ui.form-segmented
                wire:model.live="questionFrequency"
                :label="__('narrative_questions.fields.frequency')"
                :help="$chosenFrequency === null ? null : __('narrative_questions.frequency_help.'.$chosenFrequency->value)"
                required
                :options="collect(NarrativeQuestionFrequency::cases())->map(fn (NarrativeQuestionFrequency $case): array => ['value' => $case->value, 'label' => $case->label(), 'test' => 'narrative-question-frequency-'.$case->value])->all()"
            />
        </div>
    @endif

    <x-slot:actions>
        <x-ui.button type="submit" tone="primary" data-test="save-narrative-question-button">
            {{ __('common.save_changes') }}
        </x-ui.button>
    </x-slot:actions>
</x-ui.modal>
