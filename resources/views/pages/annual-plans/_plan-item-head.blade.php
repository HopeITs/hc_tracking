{{-- The header row of one planned activity: name, its summary once ready, and the Ready / Needs details badge. --}}
@php
    $summaryBatches = $this->batchRows($item);
    $countTotal = array_sum(array_map(fn (array $batch): int => (int) ($batch['count'] ?? 0), $summaryBatches));
    $placeNames = array_values(array_intersect_key($this->cityNames, array_flip($item->cityIds())));
    $showSummary = $item->is_ready || $readOnly;
@endphp

<button type="button" @class(['ap-act-h', 'is-static' => $readOnly]) @unless ($readOnly) wire:click="toggleItem({{ $item->id }})" @endunless aria-expanded="{{ $isOpen ? 'true' : 'false' }}" @if ($readOnly) tabindex="-1" @endif>
    <b class="ap-sp" dir="auto">{{ $item->activity?->statement }}</b>
    @if ($showSummary)
        <span class="ap-sm ap-muted">{{ $item->is_single_week ? trans_choice('annual_plans.wf.single_weeks', count($summaryBatches), ['count' => count($summaryBatches)]) : trans_choice('annual_plans.wf.batches', count($summaryBatches), ['count' => count($summaryBatches)]) }} · {{ $item->counted($countTotal) }} · {{ implode('، ', $placeNames) }}</span>
    @endif
    @unless ($readOnly)
        <span class="ap-badge {{ $item->is_ready ? 'is-ok' : 'is-no' }}" data-item-badge="{{ $item->is_ready ? 'ready' : 'needs' }}">{{ $item->is_ready ? __('annual_plans.wf.ready') : __('annual_plans.wf.needs_details') }}</span>
        <span class="ap-faint" aria-hidden="true">{{ $isOpen ? '▲' : '▼' }}</span>
    @endunless
</button>
