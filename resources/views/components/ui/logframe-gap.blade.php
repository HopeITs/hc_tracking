{{-- A chain gap marker (T16) as a text badge, never colour alone: no_indicators, no_target, no_milestone, unlinked_milestone. --}}
@props(['gap'])

@php
    $label = match ($gap) {
        'no_indicators' => __('results_framework.chain_gap_no_indicators'),
        'no_target' => __('results_framework.chain_gap_no_target'),
        'no_milestone' => __('results_framework.chain_gap_no_milestone'),
        'unlinked_milestone' => __('results_framework.chain_gap_unlinked_milestone'),
    };
@endphp

<span {{ $attributes->class('inline-flex items-center gap-1 rounded-full border border-status-warn/40 bg-status-warn-soft px-2 py-0.5 text-xs font-semibold text-secondary-dark dark:border-line dark:bg-surface-2 dark:text-ink') }} data-chain-gap="{{ $gap }}">
    <flux:icon.exclamation-triangle variant="micro" class="size-3 text-status-warn" aria-hidden="true" />
    {{ $label }}
</span>
