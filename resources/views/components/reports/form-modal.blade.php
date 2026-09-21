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
@endphp

<flux:modal wire:model="showReportModal" class="w-full max-w-2xl border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
    @if ($open)
        <form wire:submit="saveReport" class="space-y-5">
            <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                <flux:heading size="lg">{{ $editing ? __('reports.edit_report') : __('reports.record_report') }}</flux:heading>
                <flux:subheading>{{ __('reports.status_and_meal_review_are_recorded_on_the_report_page_not_h') }}</flux:subheading>
            </div>

            <div>
                <x-ui.select wire:model.live="reportTargetId" :label="__('reports.target')" required>
                    <flux:select.option value="">{{ __('reports.select_a_target') }}</flux:select.option>
                    @foreach ($targetOptions as $targetOption)
                        <flux:select.option :value="$targetOption['id']" :wire:key="'rpt-target-'.$targetOption['id']">{{ $targetOption['label'] }}</flux:select.option>
                    @endforeach
                </x-ui.select>
                @if ($window !== null)
                    <p class="mt-1.5 type-meta text-secondary-medium dark:text-secondary-soft">
                        {{ __('reports.figures_for_this_target_cover', ['from' => $window[0]->translatedFormat(__('formats.date')), 'to' => $window[1]->translatedFormat(__('formats.date'))]) }}
                        @if ($isPercentage)
                            · {{ __('reports.report_the_level_reached_out_of_100') }}
                        @endif
                    </p>
                @endif
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input
                    wire:model="plannedDate"
                    :label="__('reports.period_covered')"
                    type="date"
                    :min="$window === null ? null : $window[0]->toDateString()"
                    :max="$window === null ? null : $window[1]->toDateString()"
                    required
                />
                <x-ui.input
                    wire:model="value"
                    :label="$unit === null || $unit === '' ? __('reports.reported_value') : __('reports.reported_value_in', ['unit' => $unit])"
                    type="number"
                    step="0.01"
                    min="0"
                    :max="$isPercentage ? 100 : null"
                    required
                />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input wire:model="periodStart" :label="__('reports.narrative_window_start')" type="date" />
                <x-ui.input wire:model="periodEnd" :label="__('reports.narrative_window_end')" type="date" />
            </div>

            <flux:textarea wire:model="narrativeNotes" :label="__('reports.narrative_notes')" rows="3" class="border-secondary-light! bg-white! text-secondary-dark! dark:bg-secondary-medium! dark:text-white!" />

            <flux:textarea wire:model="executiveSummary" :label="__('reports.executive_summary')" rows="3" class="border-secondary-light! bg-white! text-secondary-dark! dark:bg-secondary-medium! dark:text-white!" />
            <flux:textarea wire:model="keyAchievements" :label="__('reports.key_achievements')" rows="3" class="border-secondary-light! bg-white! text-secondary-dark! dark:bg-secondary-medium! dark:text-white!" />
            <flux:textarea wire:model="challenges" :label="__('reports.challenges')" rows="3" class="border-secondary-light! bg-white! text-secondary-dark! dark:bg-secondary-medium! dark:text-white!" />
            <flux:textarea wire:model="lessonsLearned" :label="__('reports.lessons_learned')" rows="3" class="border-secondary-light! bg-white! text-secondary-dark! dark:bg-secondary-medium! dark:text-white!" />
            <flux:textarea wire:model="nextPeriodPlans" :label="__('reports.next_period_plans')" rows="3" class="border-secondary-light! bg-white! text-secondary-dark! dark:bg-secondary-medium! dark:text-white!" />

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full sm:min-h-10 sm:w-auto" wire:click="closeReportModal">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" class="min-h-11 w-full sm:min-h-10 sm:w-auto" data-test="save-report-button">
                    {{ $editing ? __('common.save_changes') : __('reports.record_report') }}
                </x-ui.button>
            </div>
        </form>
    @endif
</flux:modal>

<flux:modal wire:model="showDeleteReportModal" wire:close="cancelDeleteReport" class="w-full max-w-md border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
    <div class="space-y-4">
        <flux:heading size="lg">{{ __('reports.delete_this_draft_report') }}</flux:heading>
        <flux:subheading>{{ __('reports.deleting_a_draft_removes_its_files_too') }}</flux:subheading>
        <div class="flex justify-end gap-2 border-t border-secondary-soft pt-4 dark:border-secondary-medium">
            <x-ui.button type="button" wire:click="cancelDeleteReport">{{ __('common.cancel') }}</x-ui.button>
            <x-ui.button type="button" tone="primary" wire:click="deleteReport" data-test="confirm-delete-report">{{ __('reports.delete_report') }}</x-ui.button>
        </div>
    </div>
</flux:modal>
