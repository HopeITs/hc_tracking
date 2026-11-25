{{--
    The indicator dialog of indicators/index and its delete confirmation.
    Included by ⚡index.blade.php.
--}}
@use('App\Enums\IndicatorMeasure')
@use('App\Enums\Period')
    <x-ui.modal
        wire:model="showIndicatorModal"
        size="xl"
        :title="__('indicators.edit_indicator')"
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
                    @if ($editingIndicatorLibraryId === null)
                        <x-ui.form-input wire:model="name" :label="__('common.indicator_name')" maxlength="255" required autofocus class="@2xl/modal:col-span-2" />
                    @endif

                    <x-ui.form-select wire:model.live="indicatorResultId" :label="__('indicators.result')" :placeholder="__('indicators.select_a_result')" class="@2xl/modal:col-span-2" required>
                        @foreach ($this->resultOptions as $resultOption)
                            <option value="{{ $resultOption->id }}">{{ $resultOption->program?->name ?? __('common.unassigned') }} · {{ $resultOption->type->label() }} · {{ Str::limit($resultOption->statement, 70) }}</option>
                        @endforeach
                    </x-ui.form-select>

                    <x-ui.form-select
                        wire:model="indicatorProjectId"
                        :label="__('common.project')"
                        class="@2xl/modal:col-span-2"
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
                    @if ($editingIndicatorLibraryId === null)
                    <x-ui.form-segmented
                        wire:model.live="indicatorMeasure"
                        :label="__('indicators.measure')"
                        class="@2xl/modal:col-span-2"
                        required
                        :options="collect(IndicatorMeasure::cases())->map(fn (IndicatorMeasure $measureCase): array => ['value' => $measureCase->value, 'label' => $measureCase->label()])->all()"
                    />

                    <x-ui.form-segmented
                        wire:model="frequency"
                        :label="__('indicators.frequency')"
                        class="@2xl/modal:col-span-2"
                        required
                        :options="collect(Period::cases())->map(fn (Period $periodCase): array => ['value' => $periodCase->value, 'label' => $periodCase->label()])->all()"
                    />
                    @endif

                    <div @class(['grid gap-x-4 gap-y-3 @2xl/modal:col-span-2', 'grid-cols-3' => ! $formIsPercentage, 'grid-cols-2' => $formIsPercentage])>
                        @if (! $formIsPercentage && $editingIndicatorLibraryId === null)
                            <x-ui.form-input wire:model="unit" :label="__('indicators.unit')" maxlength="50" :placeholder="__('indicators.unit_placeholder')" />
                        @endif
                        <x-ui.form-input wire:model="baseline" :label="$formIsPercentage ? __('indicators.baseline_percent') : __('indicators.baseline')" type="number" step="0.01" min="0" :max="$formIsPercentage ? 100 : null" :suffix="$formIsPercentage ? '%' : null" />
                        <x-ui.form-input wire:model="target" :label="$formIsPercentage ? __('indicators.lifetime_target_percent') : __('indicators.lifetime_target')" type="number" step="0.01" min="0" :max="$formIsPercentage ? 100 : null" :suffix="$formIsPercentage ? '%' : null" />
                    </div>
                </div>
            </x-ui.form-section>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="save-indicator-button">
                {{ __('common.save_changes') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.modal>

    <x-ui.confirm-modal
        permanent
        wire:model="showDeleteModal"
        close="cancelDelete"
        :title="__('indicators.delete_this_indicator')"
        :message="$this->deletingIndicatorName !== null ? __('indicators.delete_named_indicator_message', ['name' => $this->deletingIndicatorName]) : __('indicators.only_an_indicator_carrying_no_targets_can_be_deleted')"
        confirm="deleteIndicator"
        :confirm-label="__('indicators.delete_indicator')"
        confirm-test="confirm-delete-indicator"
    />
