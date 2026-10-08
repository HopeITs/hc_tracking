{{--
    The indicator dialog of indicators/index and its delete confirmation.
    Included by ⚡index.blade.php.
--}}
@use('App\Enums\IndicatorMeasure')
@use('App\Enums\Period')
    <x-ui.modal
        wire:model="showIndicatorModal"
        size="xl"
        :title="$editingIndicatorId === null ? __('indicators.add_indicator') : __('indicators.edit_indicator')"
        :description="__('indicators.an_indicator_measures_exactly_one_result')"
        submit="saveIndicator"
        close="closeIndicatorModal"
        required-note
    >
        @if ($showIndicatorModal)
            @php
                $formIsPercentage = $indicatorMeasure === IndicatorMeasure::Percentage->value;
            @endphp

            <x-ui.form-section :title="__('common.section_details')" :fields="['name', 'indicatorResultId', 'indicatorProjectId']">
                <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                    <x-ui.form-input wire:model="name" :label="__('common.indicator_name')" maxlength="255" required autofocus class="@2xl/modal:col-span-2" />

                    <x-ui.form-select wire:model.live="indicatorResultId" :label="__('indicators.result')" :placeholder="__('indicators.select_a_result')" required>
                        @foreach ($this->resultOptions as $resultOption)
                            <option value="{{ $resultOption->id }}">{{ $resultOption->type->label() }} · {{ Str::limit($resultOption->statement, 60) }}</option>
                        @endforeach
                    </x-ui.form-select>

                    <x-ui.form-select
                        wire:model="indicatorProjectId"
                        :label="__('common.project')"
                        :help="$indicatorResultId !== '' ? __('indicators.only_projects_delivering_the_result_program_are_offered') : null"
                    >
                        <option value="">{{ __('common.organisation_wide') }}</option>
                        @foreach ($this->projectOptions as $projectOption)
                            <option value="{{ $projectOption->id }}">{{ $projectOption->name }}</option>
                        @endforeach
                    </x-ui.form-select>
                </div>
            </x-ui.form-section>

            <x-ui.form-section :title="__('indicators.section_measurement')" :fields="['indicatorMeasure', 'frequency', 'unit', 'baseline', 'target']">
                <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                    <x-ui.form-segmented
                        wire:model.live="indicatorMeasure"
                        :label="__('indicators.measure')"
                        required
                        :options="collect(IndicatorMeasure::cases())->map(fn (IndicatorMeasure $measureCase): array => ['value' => $measureCase->value, 'label' => $measureCase->label()])->all()"
                    />

                    <x-ui.form-segmented
                        wire:model="frequency"
                        :label="__('indicators.frequency')"
                        required
                        :options="collect(Period::cases())->map(fn (Period $periodCase): array => ['value' => $periodCase->value, 'label' => $periodCase->label()])->all()"
                    />

                    <div @class(['grid gap-x-4 gap-y-3 @2xl/modal:col-span-2', 'grid-cols-3' => ! $formIsPercentage, 'grid-cols-2' => $formIsPercentage])>
                        @unless ($formIsPercentage)
                            <x-ui.form-input wire:model="unit" :label="__('indicators.unit')" maxlength="50" :placeholder="__('indicators.unit_placeholder')" />
                        @endunless
                        <x-ui.form-input wire:model="baseline" :label="$formIsPercentage ? __('indicators.baseline_percent') : __('indicators.baseline')" type="number" step="0.01" min="0" :max="$formIsPercentage ? 100 : null" :suffix="$formIsPercentage ? '%' : null" />
                        <x-ui.form-input wire:model="target" :label="$formIsPercentage ? __('indicators.lifetime_target_percent') : __('indicators.lifetime_target')" type="number" step="0.01" min="0" :max="$formIsPercentage ? 100 : null" :suffix="$formIsPercentage ? '%' : null" />
                    </div>
                </div>
            </x-ui.form-section>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="save-indicator-button">
                {{ $editingIndicatorId === null ? __('indicators.create_indicator') : __('common.save_changes') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.modal>

    <x-ui.confirm-modal
        wire:model="showDeleteModal"
        close="cancelDelete"
        :title="__('indicators.delete_this_indicator')"
        :message="__('indicators.only_an_indicator_carrying_no_targets_can_be_deleted')"
        confirm="deleteIndicator"
        :confirm-label="__('indicators.delete_indicator')"
        confirm-test="confirm-delete-indicator"
    />
