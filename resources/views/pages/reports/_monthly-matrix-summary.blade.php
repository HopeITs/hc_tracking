{{--
    The four summary cards of the Monthly Matrix (ReadsMatrixProgress): on
    pace, behind pace, waiting for validation and missing reports. Each card
    is a quick filter: pressing it shows only those rows, pressing it again
    shows all. Included by ⚡monthly-matrix.blade.php.
--}}
@php
    $summary = $this->matrixSummary;
    $summaryCards = [
        ['key' => 'pace', 'label' => __('reports.matrix.summary_pace'), 'value' => $summary['pace'], 'unit' => __('reports.matrix.of_indicators', ['count' => $summary['total']]), 'hint' => __('reports.matrix.summary_pace_hint'), 'alarm' => false],
        ['key' => 'behind', 'label' => __('reports.matrix.summary_behind'), 'value' => $summary['behind'], 'unit' => trans_choice('reports.matrix.unit_indicators', $summary['behind']), 'hint' => __('reports.matrix.summary_behind_hint'), 'alarm' => $summary['behind'] > 0],
        ['key' => 'waiting', 'label' => __('reports.matrix.summary_waiting'), 'value' => $summary['waiting'], 'unit' => trans_choice('reports.matrix.unit_reports', $summary['waiting']), 'hint' => __('reports.matrix.summary_waiting_hint'), 'alarm' => false],
        ['key' => 'missing', 'label' => __('reports.matrix.summary_missing'), 'value' => $summary['missing'], 'unit' => trans_choice('reports.matrix.unit_reports', $summary['missing']), 'hint' => __('reports.matrix.summary_missing_hint'), 'alarm' => $summary['missing'] > 0],
    ];
@endphp

<section class="mm-stats" aria-label="{{ __('reports.matrix.summary') }}" data-matrix-summary>
    @foreach ($summaryCards as $card)
        <button type="button" wire:click="setFocus('{{ $card['key'] }}')" aria-pressed="{{ $focus === $card['key'] ? 'true' : 'false' }}" @class(['mm-stat', 'mm-stat-alarm' => $card['alarm']]) data-test="matrix-focus-{{ $card['key'] }}">
            <span class="mm-stat-label">{{ $card['label'] }}</span>
            <span class="mm-stat-figure"><b class="dk-num">{{ $card['value'] }}</b><span>{{ $card['unit'] }}</span></span>
            <span class="mm-stat-hint">{{ $card['hint'] }}</span>
        </button>
    @endforeach
</section>
