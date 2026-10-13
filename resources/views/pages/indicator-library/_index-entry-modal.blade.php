{{--
    The entry dialog of indicator-library/index. Included by ⚡index.blade.php.

    The code is never typed: it is generated on save from the program and
    level. Program and level are frozen once an indicator uses the entry, and
    the measure once one of those indicators has targets; the action checks
    both again under lock, so these disabled fields are only a courtesy.
--}}
@use('App\Enums\CollectionFrequency')
@use('App\Enums\DenominatorBasis')
@use('App\Enums\IndicatorCollector')
@use('App\Enums\IndicatorMeasure')
@use('App\Enums\ResultType')
<x-ui.modal
    wire:model="showEntryModal"
    size="xl"
    :title="$editingEntryId === null ? __('indicator_library.add_entry') : __('indicator_library.edit_entry')"
    :description="__('indicator_library.entry_dialog_description')"
    submit="saveEntry"
    close="closeEntryModal"
    required-note
>
    @if ($showEntryModal)
        @php
            $isInstitutional = filter_var($entry['institutional'] ?? false, FILTER_VALIDATE_BOOL);
            $isPercentage = ($entry['measure'] ?? '') === IndicatorMeasure::Percentage->value;
            $isFollowUp = ($entry['frequency'] ?? '') === CollectionFrequency::FollowUp->value;
            $yesNo = [['value' => '1', 'label' => __('indicator_library.yes')], ['value' => '0', 'label' => __('indicator_library.no')]];
        @endphp

        <x-ui.form-section :title="__('indicator_library.section_placement')" :fields="['entry.institutional', 'entry.program_id', 'entry.level_ref']">
            <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                <x-ui.form-segmented
                    wire:model.live="entry.institutional"
                    :label="__('indicator_library.fields.institutional')"
                    :help="__('indicator_library.institutional_help')"
                    :options="$yesNo"
                    :disabled="$entryIsInUse"
                />

                @unless ($isInstitutional)
                    <x-ui.form-select
                        wire:model="entry.program_id"
                        :label="__('common.program')"
                        :placeholder="__('indicator_library.choose_program')"
                        required
                        :disabled="$entryIsInUse"
                        :locked-reason="__('indicator_library.code_frozen')"
                    >
                        @foreach ($this->entryProgramOptions as $programOption)
                            <option value="{{ $programOption->id }}">{{ $programOption->name }}</option>
                        @endforeach
                    </x-ui.form-select>
                @endunless

                <x-ui.form-input
                    wire:model="entry.level_ref"
                    :label="__('indicator_library.fields.level_ref')"
                    :help="$entryIsInUse ? __('indicator_library.code_frozen') : __('indicator_library.level_ref_help')"
                    maxlength="20"
                    dir="ltr"
                    placeholder="OP1.2"
                    required
                    :disabled="$entryIsInUse"
                />

                <div class="min-w-0 self-end pb-2.5">
                    <p class="type-label text-secondary-medium dark:text-ink-muted">{{ __('indicator_library.fields.code') }}</p>
                    @if ($editingCode !== null)
                        <bdi dir="ltr" class="font-mono text-sm font-semibold">{{ $editingCode }}</bdi>
                    @else
                        <p class="text-sm text-secondary-medium dark:text-ink-muted">{{ __('indicator_library.code_generated_on_save') }}</p>
                    @endif
                </div>
            </div>
        </x-ui.form-section>

        <x-ui.form-section :title="__('indicator_library.section_name')" :fields="['entry.name_ar', 'entry.name_en']">
            <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                <x-ui.form-input wire:model="entry.name_ar" :label="__('indicator_library.fields.name_ar')" maxlength="255" dir="rtl" lang="ar" required autofocus />
                <x-ui.form-input wire:model="entry.name_en" :label="__('indicator_library.fields.name_en')" :help="__('indicator_library.name_en_help')" maxlength="255" dir="ltr" lang="en" />
            </div>
        </x-ui.form-section>

        <x-ui.form-section :title="__('indicator_library.section_measurement')" :fields="['entry.measure', 'entry.unit', 'entry.denominator_basis', 'entry.numerator_label', 'entry.denominator_label', 'entry.calculation_method']">
            <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                <x-ui.form-segmented
                    wire:model.live="entry.measure"
                    :label="__('indicator_library.measure')"
                    required
                    :disabled="$measureIsLocked"
                    :help="$measureIsLocked ? __('indicator_library.measure_locked') : __('indicator_library.measure_help')"
                    :options="collect(IndicatorMeasure::cases())->map(fn (IndicatorMeasure $measureCase): array => ['value' => $measureCase->value, 'label' => $measureCase->label()])->all()"
                />

                @if ($isPercentage)
                    <x-ui.form-select wire:model="entry.denominator_basis" :label="__('indicator_library.fields.denominator_basis')" :help="__('indicator_library.denominator_basis_help')">
                        <option value="">{{ __('indicator_library.basis_default', ['basis' => $this->defaultBasisLabel()]) }}</option>
                        @foreach (DenominatorBasis::cases() as $basisCase)
                            <option value="{{ $basisCase->value }}">{{ $basisCase->label() }}</option>
                        @endforeach
                    </x-ui.form-select>

                    <x-ui.form-input wire:model="entry.numerator_label" :label="__('indicator_library.fields.numerator_label')" maxlength="255" />
                    <x-ui.form-input wire:model="entry.denominator_label" :label="__('indicator_library.fields.denominator_label')" maxlength="255" />
                @else
                    <x-ui.form-input wire:model="entry.unit" :label="__('indicator_library.fields.unit')" :placeholder="__('indicators.unit_placeholder')" maxlength="50" />
                @endif

                <x-ui.form-textarea wire:model="entry.calculation_method" :label="__('indicator_library.fields.calculation_method')" rows="2" maxlength="5000" class="@2xl/modal:col-span-2" />
            </div>
        </x-ui.form-section>

        <x-ui.form-section :title="__('indicator_library.section_collection')" :fields="['entry.collected_by', 'entry.frequency', 'entry.follow_up_months', 'entry.frequency_note', 'entry.is_disaggregated', 'entry.is_cumulative']">
            <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                <x-ui.form-segmented
                    wire:model.live="entry.collected_by"
                    :label="__('indicator_library.collected_by')"
                    required
                    :options="collect(IndicatorCollector::cases())->map(fn (IndicatorCollector $collectorCase): array => ['value' => $collectorCase->value, 'label' => $collectorCase->label()])->all()"
                />

                <x-ui.form-select wire:model.live="entry.frequency" :label="__('indicator_library.fields.frequency')" :placeholder="__('indicator_library.choose_frequency')" required>
                    @foreach (CollectionFrequency::cases() as $frequencyCase)
                        <option value="{{ $frequencyCase->value }}">{{ $frequencyCase->label() }}</option>
                    @endforeach
                </x-ui.form-select>

                @if ($isFollowUp)
                    <x-ui.form-input wire:model="entry.follow_up_months" :label="__('indicator_library.fields.follow_up_months')" type="number" min="1" max="120" step="1" required />
                @endif

                <x-ui.form-textarea wire:model="entry.frequency_note" :label="__('indicator_library.fields.frequency_note')" :help="__('indicator_library.frequency_note_help')" rows="2" maxlength="5000" class="@2xl/modal:col-span-2" />

                <x-ui.form-segmented wire:model="entry.is_disaggregated" :label="__('indicator_library.fields.is_disaggregated')" :help="__('indicator_library.disaggregated_help')" :options="$yesNo" />
                <x-ui.form-segmented wire:model="entry.is_cumulative" :label="__('indicator_library.fields.is_cumulative')" :help="__('indicator_library.cumulative_help')" :options="$yesNo" />
            </div>
        </x-ui.form-section>

        <x-ui.form-section :title="__('indicator_library.section_definition')" :fields="['entry.definition', 'entry.suggested_level', 'entry.data_source', 'entry.means_of_verification']">
            <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                <x-ui.form-textarea wire:model="entry.definition" :label="__('indicator_library.fields.definition')" :help="__('indicator_library.definition_help')" rows="3" maxlength="5000" class="@2xl/modal:col-span-2" />

                <x-ui.form-select wire:model="entry.suggested_level" :label="__('indicator_library.fields.suggested_level')">
                    <option value="">{{ __('indicator_library.no_suggested_level') }}</option>
                    @foreach (ResultType::cases() as $levelCase)
                        <option value="{{ $levelCase->value }}">{{ $levelCase->label() }}</option>
                    @endforeach
                </x-ui.form-select>

                <x-ui.form-input wire:model="entry.data_source" :label="__('indicator_library.fields.data_source')" maxlength="5000" />
                <x-ui.form-textarea wire:model="entry.means_of_verification" :label="__('indicator_library.fields.means_of_verification')" rows="2" maxlength="5000" class="@2xl/modal:col-span-2" />
            </div>
        </x-ui.form-section>
    @endif

    <x-slot:actions>
        <x-ui.button type="submit" tone="primary" data-test="save-library-entry">
            {{ $editingEntryId === null ? __('indicator_library.add_entry') : __('common.save_changes') }}
        </x-ui.button>
    </x-slot:actions>
</x-ui.modal>
