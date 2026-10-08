@use('App\Enums\IndicatorMeasure')
@use('App\Enums\Period')
    <x-ui.modal
        wire:model="showTargetModal"
        size="xl"
        :title="$editingTargetId === null ? __('targets.commit_target') : __('targets.edit_target')"
        :description="__('targets.a_target_is_committed_either_in_an_annual_operational_plan_o')"
        submit="saveTarget"
        close="closeTargetModal"
        required-note
    >
        @if ($showTargetModal)
            @php
                $formIndicator = $this->formIndicator;
                $formIsPercentage = $formIndicator?->measure === IndicatorMeasure::Percentage;
                $indicatorHelp = $formIndicator === null ? null : $formIndicator->measure->label().($formIndicator->project !== null ? ' · '.__('targets.scoped_to_project', ['project' => $formIndicator->project->name]) : '');
            @endphp

            {{-- The indicator comes first: it decides which plans and projects fit. --}}
            <x-ui.form-section :title="__('common.section_details')" :fields="['targetIndicatorId', 'targetSourceType', 'targetSourceId']">
                <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                    <x-ui.form-select wire:model.live="targetIndicatorId" :label="__('targets.indicator')" :placeholder="__('targets.select_an_indicator')" :help="$indicatorHelp" required autofocus class="@2xl/modal:col-span-2">
                        @foreach ($this->indicatorOptions as $indicatorOption)
                            <option value="{{ $indicatorOption->id }}">{{ $indicatorOption->name }} — {{ Str::limit($indicatorOption->result?->statement, 50) }}</option>
                        @endforeach
                    </x-ui.form-select>

                    <x-ui.form-segmented
                        wire:model.live="targetSourceType"
                        :label="__('targets.committed_in')"
                        required
                        :options="collect($sourceTypeLabels)->map(fn (string $sourceLabel, string $sourceKey): array => ['value' => $sourceKey, 'label' => $sourceLabel])->values()->all()"
                    />

                    <x-ui.form-select
                        wire:model.live="targetSourceId"
                        :label="$targetSourceType === 'proposal' ? __('common.proposal') : __('common.annual_plan')"
                        :placeholder="__('targets.select_a_source')"
                        :help="$targetSourceType === 'annual_plan' && $formIndicator?->result?->program_id !== null ? __('targets.only_plans_of_the_result_program_are_offered') : null"
                        required
                    >
                        @foreach ($this->sourceOptions as $sourceOption)
                            <option value="{{ $sourceOption['id'] }}">{{ $sourceOption['label'] }}</option>
                        @endforeach
                    </x-ui.form-select>
                </div>
            </x-ui.form-section>

            <x-ui.form-section :title="__('targets.section_commitment')" :fields="['targetValue', 'targetPeriod', 'targetProjectId']">
                <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                    <x-ui.form-input wire:model="targetValue" :label="$formIsPercentage ? __('targets.target_value_percent') : __('targets.target_value')" type="number" step="0.01" min="0" :max="$formIsPercentage ? 100 : null" :suffix="$formIsPercentage ? '%' : null" required />

                    <x-ui.form-segmented
                        wire:model="targetPeriod"
                        :label="__('targets.reporting_period')"
                        required
                        :options="collect(Period::cases())->map(fn (Period $periodCase): array => ['value' => $periodCase->value, 'label' => $periodCase->label()])->all()"
                    />

                    <x-ui.form-select
                        wire:model="targetProjectId"
                        :label="__('common.project')"
                        :disabled="$formIndicator?->project_id !== null"
                        :locked-reason="$formIndicator?->project !== null ? __('targets.scoped_to_project', ['project' => $formIndicator->project->name]) : null"
                        :help="$targetSourceId !== '' ? ($targetSourceType === 'proposal' ? __('targets.only_projects_the_proposal_funds_are_offered') : __('targets.only_projects_in_the_plan_program_are_offered')) : null"
                        class="@2xl/modal:col-span-2"
                    >
                        @if ($formIndicator?->project_id === null)
                            <option value="">{{ __('common.organisation_wide') }}</option>
                        @endif
                        @foreach ($this->formProjectOptions as $projectOption)
                            <option value="{{ $projectOption->id }}">{{ $projectOption->name }}</option>
                        @endforeach
                    </x-ui.form-select>
                </div>
            </x-ui.form-section>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="save-target-button">
                {{ $editingTargetId === null ? __('targets.commit_target') : __('common.save_changes') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.modal>
