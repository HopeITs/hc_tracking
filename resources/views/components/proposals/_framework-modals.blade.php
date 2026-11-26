{{-- The framework box's dialogs: Mark as done, assumption, and the shared result/indicator/risk forms (risk form: components/risks/form-modal). --}}
@php
    $openChecks = \App\Support\FrameworkChecks::open($checks);
@endphp

<x-ui.modal wire:model="showDoneModal" size="sm" submit="markDone" :title="$openChecks === [] ? __('proposals.framework.done_title_ready') : trans_choice('proposals.framework.done_title_left', count($openChecks), ['count' => count($openChecks)])">
    @if ($showDoneModal)
        <div class="flex flex-col gap-4" data-test="framework-done-form">
            @if ($openChecks === [])
                <p class="text-sm">{{ __('proposals.framework.done_ready_text') }}</p>
            @else
                <ul class="flex flex-col gap-1.5 text-sm">
                    @foreach ($openChecks as $check)
                        <li><span class="font-bold text-status-critical-text" aria-hidden="true">!</span> {{ __('proposals.framework.check_'.$check['key']) }}</li>
                    @endforeach
                </ul>
                <p class="text-sm">{{ __('proposals.framework.done_anyway_text') }}</p>
            @endif
            <x-ui.form-textarea wire:model="doneNote" :label="$openChecks === [] ? __('proposals.framework.note_optional') : __('proposals.framework.why_done_anyway')" :placeholder="$openChecks === [] ? '' : __('proposals.framework.why_placeholder')" :required="$openChecks !== []" data-test="framework-done-note" />
        </div>
    @endif
    <x-slot:actions>
        <x-ui.button type="submit" tone="primary" data-test="framework-done-submit">{{ $openChecks === [] ? __('proposals.framework.mark_done_short') : __('proposals.framework.mark_done_anyway') }}</x-ui.button>
    </x-slot:actions>
</x-ui.modal>

<x-ui.modal wire:model="showAssumptionModal" size="sm" submit="saveAssumption" :title="$assumptionId === null ? __('proposals.framework.add_assumption') : __('proposals.framework.edit_assumption')">
    @if ($showAssumptionModal)
        <div class="flex flex-col gap-4" data-test="assumption-form">
            <x-ui.form-textarea wire:model="assumptionStatement" :label="__('proposals.framework.assumption')" required />
            <x-ui.form-select wire:model="assumptionResultId" :label="__('proposals.framework.needed_for')" required>
                <option value="">{{ __('proposals.framework.choose_result') }}</option>
                @foreach ($this->framework['results'] as $option)
                    <option value="{{ $option['id'] }}">{{ $option['type']->label() }}: {{ \Illuminate\Support\Str::limit($option['statement'], 70) }}</option>
                @endforeach
            </x-ui.form-select>
            <x-ui.form-select wire:model="assumptionStatus" :label="__('proposals.framework.does_it_hold')">
                @foreach (\App\Enums\AssumptionStatus::cases() as $status)
                    <option value="{{ $status->value }}">{{ $status->label() }}</option>
                @endforeach
            </x-ui.form-select>
        </div>
    @endif
    @if ($assumptionId !== null)
        <x-slot:footerMeta>
            <button type="button" class="text-sm font-semibold underline underline-offset-2" wire:click="deleteAssumption" wire:confirm="{{ __('proposals.framework.delete_assumption_confirm') }}" data-test="assumption-delete">{{ __('proposals.framework.delete_assumption') }}</button>
        </x-slot:footerMeta>
    @endif
    <x-slot:actions>
        <x-ui.button type="submit" tone="primary" data-test="assumption-save">{{ $assumptionId === null ? __('proposals.framework.add_assumption') : __('common.save') }}</x-ui.button>
    </x-slot:actions>
</x-ui.modal>

<x-risks.form-modal
    show="showRiskModal"
    form="riskForm"
    submit="saveRisk"
    close="closeRiskForm"
    :open="$showRiskModal"
    :editing="$editingRiskId !== null"
    :suggestions="$this->riskSuggestions"
/>

@include('pages.results._index-result-modal')

<livewire:indicators.quick-form :key="'logframe-indicator-form-'.$proposalId" />

@if ($canCreate)
    @include('components.proposals._logframe-copy-modal')
@endif

<x-ui.confirm-modal
    permanent
    wire:model="showDeleteModal"
    close="cancelDelete"
    :title="__('results_framework.delete_this_result')"
    :message="__('results_framework.only_a_result_with_no_nested_results_and_no_indicators_can_b')"
    confirm="deleteResult"
    :confirm-label="__('results_framework.delete_result')"
    confirm-test="confirm-delete-result"
/>

{{-- Centre targets (EditsCentreTargets): one number per funded project's centre, one period for all. --}}
<x-ui.modal wire:model="showCentreTargetsModal" size="md" submit="saveCentreTargets" close="closeCentreTargets" :title="__('proposals.framework.centre_targets_title')">
    @if ($showCentreTargetsModal && ($targetIndicator = $this->centreTargetIndicator) !== null)
        @php
            $targetRows = $this->centreTargetRows;
        @endphp
        <div class="flex flex-col gap-4" data-test="centre-targets-form"
            x-data="{
                total: {{ $targetIndicator['target'] === null ? 'null' : $targetIndicator['target'] }},
                percent: {{ $targetIndicator['percent'] ? 'true' : 'false' }},
                sum: 0,
                add() { this.sum = [...this.$el.querySelectorAll('[data-centre-target]')].reduce((s, i) => s + (parseFloat(i.value) || 0), 0) },
                fmt(n) { return Math.round(n * 100) / 100 },
            }"
            x-init="add()" x-on:input="add()">
            <p class="text-sm" dir="auto">
                <b>{{ $targetIndicator['name'] }}</b>
                <span class="text-ink-muted">· {{ $targetIndicator['target'] === null ? __('proposals.framework.no_target_yet') : __('proposals.framework.indicator_target_is', ['target' => rtrim(rtrim(number_format($targetIndicator['target'], 2, '.', ''), '0'), '.'), 'unit' => $targetIndicator['unit'] ?? '']) }}</span>
            </p>

            @if (! $targetIndicator['linked'])
                <p class="text-sm text-status-critical-text" data-test="centre-targets-not-linked">{{ __('targets.cell_indicator_not_linked') }}</p>
            @elseif ($targetRows === [])
                <p class="text-sm" data-test="centre-targets-none">{{ __('proposals.framework.centre_targets_no_projects') }}</p>
            @else
                <x-ui.form-select wire:model="centreTargetsPeriod" :label="__('proposals.framework.centre_targets_period')" required data-test="centre-targets-period">
                    @foreach (\App\Enums\Period::cases() as $period)
                        <option value="{{ $period->value }}">{{ $period->label() }}</option>
                    @endforeach
                </x-ui.form-select>

                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach ($targetRows as $row)
                        <x-ui.form-input type="number" min="0" step="any" wire:model="centreTargets.{{ $row['projectId'] }}" :label="$row['label']" :placeholder="__('proposals.framework.not_committed')" data-centre-target data-test="centre-target-{{ $row['projectId'] }}" wire:key="centre-target-{{ $row['projectId'] }}" />
                    @endforeach
                </div>

                <p class="text-sm font-semibold" aria-live="polite" data-test="centre-targets-sum">
                    <span x-show="percent">{{ __('proposals.framework.centre_sum_percent') }}</span>
                    <span x-show="! percent && total === null" x-text="@js(__('proposals.framework.centre_sum_plain')).replace(':sum', fmt(sum))"></span>
                    <span x-show="! percent && total !== null && fmt(sum) === fmt(total)" x-text="@js(__('proposals.framework.centre_sum_match')).replace(':sum', fmt(sum))"></span>
                    <span x-show="! percent && total !== null && sum < total" class="text-status-critical-text" x-text="@js(__('proposals.framework.centre_sum_short')).replace(':sum', fmt(sum)).replace(':total', fmt(total)).replace(':left', fmt(total - sum))"></span>
                    <span x-show="! percent && total !== null && sum > total && fmt(sum) !== fmt(total)" class="text-status-critical-text" x-text="@js(__('proposals.framework.centre_sum_over')).replace(':sum', fmt(sum)).replace(':total', fmt(total)).replace(':over', fmt(sum - total))"></span>
                </p>

                @error('centreTargets')
                    <p class="text-sm text-status-critical-text" role="alert">{{ $message }}</p>
                @enderror
            @endif
        </div>
    @endif
    <x-slot:actions>
        @if ($showCentreTargetsModal && ($this->centreTargetIndicator['linked'] ?? false) && $this->centreTargetRows !== [])
            <x-ui.button type="submit" tone="primary" data-test="centre-targets-save">{{ __('proposals.framework.save_targets') }}</x-ui.button>
        @endif
    </x-slot:actions>
</x-ui.modal>
