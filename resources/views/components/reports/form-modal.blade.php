{{--
    The report form, rendered by every page that uses App\Livewire\Concerns\ManagesReportForm.
    Its wire:model bindings resolve against that page's component.
--}}
@props([
    'open' => false,
    'editing' => false,
    'targetOptions' => [],
    'formTarget' => null,
    'window' => null,
])

@php
    $isPercentage = $formTarget?->indicator?->measure === App\Enums\IndicatorMeasure::Percentage;
    $unit = $isPercentage ? '%' : $formTarget?->indicator?->unit;
    $targetHelp = $window === null ? null : __('reports.figures_for_this_target_cover', [
        'from' => $window[0]->translatedFormat(__('formats.date')),
        'to' => $window[1]->translatedFormat(__('formats.date')),
    ]).($isPercentage ? ' · '.__('reports.report_the_level_reached_out_of_100') : '');
@endphp

<x-ui.modal
    wire:model="showReportModal"
    size="xl"
    :title="$editing ? __('reports.edit_report') : __('reports.record_report')"
    :description="__('reports.status_and_meal_review_are_recorded_on_the_report_page_not_h')"
    submit="saveReport"
    close="closeReportModal"
    required-note
>
    @if ($open)
        <x-ui.form-section :title="__('common.section_details')" :fields="['reportTargetId', 'plannedDate', 'value']">
            <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                <x-ui.form-select wire:model.live="reportTargetId" :label="__('reports.target')" :placeholder="__('reports.select_a_target')" :help="$targetHelp" required autofocus class="@2xl/modal:col-span-2">
                    @foreach ($targetOptions as $targetOption)
                        <flux:select.option :value="$targetOption['id']">{{ $targetOption['label'] }}</flux:select.option>
                    @endforeach
                </x-ui.form-select>

                <x-ui.form-input
                    wire:model="plannedDate"
                    :label="__('reports.period_covered')"
                    type="date"
                    :min="$window === null ? null : $window[0]->toDateString()"
                    :max="$window === null ? null : $window[1]->toDateString()"
                    required
                />

                <x-ui.form-input
                    wire:model="value"
                    :label="$unit === null || $unit === '' ? __('reports.reported_value') : __('reports.reported_value_in', ['unit' => $unit])"
                    type="number"
                    step="0.01"
                    min="0"
                    :max="$isPercentage ? 100 : null"
                    :suffix="$isPercentage ? '%' : null"
                    required
                />
            </div>
        </x-ui.form-section>

        <x-ui.form-section :title="__('reports.section_narrative')" :fields="['periodStart', 'periodEnd', 'narrativeNotes']">
            <div class="grid grid-cols-2 gap-x-4 gap-y-3">
                <x-ui.form-input wire:model="periodStart" :label="__('reports.narrative_window_start')" type="date" />
                <x-ui.form-input wire:model="periodEnd" :label="__('reports.narrative_window_end')" type="date" />
                <x-ui.form-textarea wire:model="narrativeNotes" :label="__('reports.narrative_notes')" rows="2" class="col-span-2" />
            </div>
        </x-ui.form-section>

        <x-ui.form-section :title="__('reports.section_summary')" :fields="['executiveSummary', 'keyAchievements', 'challenges', 'lessonsLearned', 'nextPeriodPlans']">
            <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                <x-ui.form-textarea wire:model="executiveSummary" :label="__('reports.executive_summary')" rows="2" class="@2xl/modal:col-span-2" />
                <x-ui.form-textarea wire:model="keyAchievements" :label="__('reports.key_achievements')" rows="2" />
                <x-ui.form-textarea wire:model="challenges" :label="__('reports.challenges')" rows="2" />
                <x-ui.form-textarea wire:model="lessonsLearned" :label="__('reports.lessons_learned')" rows="2" />
                <x-ui.form-textarea wire:model="nextPeriodPlans" :label="__('reports.next_period_plans')" rows="2" />
            </div>
        </x-ui.form-section>
    @endif

    <x-slot:actions>
        <x-ui.button type="submit" tone="primary" data-test="save-report-button">
            {{ $editing ? __('common.save_changes') : __('reports.record_report') }}
        </x-ui.button>
    </x-slot:actions>
</x-ui.modal>

<x-ui.confirm-modal
    wire:model="showDeleteReportModal"
    close="cancelDeleteReport"
    :title="__('reports.delete_this_draft_report')"
    :message="__('reports.deleting_a_draft_removes_its_files_too')"
    confirm="deleteReport"
    :confirm-label="__('reports.delete_report')"
    confirm-test="confirm-delete-report"
/>
