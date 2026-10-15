@use('App\Enums\ResultType')
@use('App\Enums\Period')

{{-- Create and edit form for a result. Rendered by ⚡index.blade.php. --}}
    <x-ui.modal
        wire:model="showResultModal"
        size="xl"
        :title="$editingResultId === null ? __('results_framework.add_result') : __('results_framework.edit_result')"
        :description="__('results_framework.an_output_contributes_to_an_outcome_which_contributes_to_an')"
        submit="saveResult"
        close="closeResultModal"
        required-note
    >
        @if ($showResultModal)
            <x-ui.form-section :title="__('common.section_details')" :fields="['statement', 'resultType', 'checkoutPeriod']">
                <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                    <x-ui.form-textarea wire:model="statement" :label="__('results_framework.statement')" required autofocus class="@2xl/modal:col-span-2" />

                    {{-- The Impact / Outcome / Output marks are the logframe's own (see components-ui rules). --}}
                    <x-ui.form-segmented
                        wire:model.live="resultType"
                        :label="__('common.type')"
                        required
                        :options="[
                            ['value' => ResultType::Impact->value, 'label' => ResultType::Impact->label(), 'dot' => 'bg-secondary-dark dark:bg-ink'],
                            ['value' => ResultType::Outcome->value, 'label' => ResultType::Outcome->label(), 'dot' => 'bg-primary'],
                            ['value' => ResultType::Output->value, 'label' => ResultType::Output->label(), 'dot' => 'bg-board ring-1 ring-secondary-light'],
                        ]"
                    />

                    <x-ui.form-segmented
                        wire:model="checkoutPeriod"
                        :label="__('results_framework.checked')"
                        required
                        :options="collect(Period::cases())->map(fn (Period $periodCase): array => ['value' => $periodCase->value, 'label' => $periodCase->label()])->all()"
                    />
                </div>
            </x-ui.form-section>

            <x-ui.form-section :title="__('common.section_placement')" :fields="['parentId', 'resultProgramId']">
                <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                    <x-ui.form-select
                        wire:model.live="parentId"
                        :label="__('results_framework.contributes_to_label')"
                        :disabled="$resultType === ResultType::Impact->value"
                        :locked-reason="__('results_framework.an_impact_sits_at_the_top_of_the_chain')"
                        :help="__('results_framework.only_the_level_directly_above_is_offered')"
                    >
                        <option value="">{{ __('common.top_level') }}</option>
                        @foreach ($this->parentOptions as $parentOption)
                            <option value="{{ $parentOption->id }}">{{ Str::limit($parentOption->statement, 60) }} · {{ $parentOption->program?->name ?? __('common.unassigned') }}</option>
                        @endforeach
                    </x-ui.form-select>

                    <x-ui.form-select
                        wire:model="resultProgramId"
                        :label="__('common.program')"
                        :disabled="$parentId !== ''"
                        :locked-reason="__('results_framework.follows_the_program_of_the_result_it_contributes_to')"
                        :help="$editingResultId !== null ? __('results_framework.nested_results_move_with_it') : null"
                    >
                        <option value="">{{ __('common.unassigned') }}</option>
                        @foreach ($this->programOptions as $programOption)
                            <option value="{{ $programOption->id }}">{{ $programOption->name }}</option>
                        @endforeach
                    </x-ui.form-select>
                </div>
            </x-ui.form-section>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="save-result-button">
                {{ $editingResultId === null ? __('results_framework.add_result') : __('common.save_changes') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.modal>
