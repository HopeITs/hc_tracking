{{--
    The three plain answers at the top of indicators/index: how many indicators
    need attention, are waiting for a measurement, or are on track. Each is a
    button that narrows the list to those; pressing it again shows everything.
    Included by ⚡index.blade.php, whose top PHP block defines $focusCounts and
    $filtersAreActive.
--}}
@php
    $tabs = [
        ['key' => 'attention', 'label' => __('indicators.tab_attention'), 'count' => $focusCounts['attention'], 'tone' => 'text-status-warn'],
        ['key' => 'waiting', 'label' => __('indicators.tab_waiting'), 'count' => $focusCounts['waiting'], 'tone' => 'text-secondary-dark dark:text-ink'],
        ['key' => 'ontrack', 'label' => __('indicators.tab_ontrack'), 'count' => $focusCounts['ontrack'], 'tone' => 'text-status-good'],
    ];
@endphp

<div class="flex flex-wrap items-stretch gap-2" data-test="indicator-focus-tabs">
    @foreach ($tabs as $tab)
        <button
            type="button"
            wire:click="setFocus('{{ $tab['key'] }}')"
            wire:key="focus-tab-{{ $tab['key'] }}"
            aria-pressed="{{ $focus === $tab['key'] ? 'true' : 'false' }}"
            data-test="focus-tab-{{ $tab['key'] }}"
            @class([
                'flex min-h-14 flex-1 basis-40 items-center gap-3 rounded-xl border px-4 py-2 text-start transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium',
                'border-secondary-dark bg-white shadow-card ring-1 ring-secondary-dark dark:border-ink dark:bg-surface-2 dark:ring-ink' => $focus === $tab['key'],
                'border-secondary-soft bg-white hover:border-secondary-light dark:border-line dark:bg-surface-1' => $focus !== $tab['key'],
            ])
        >
            <span class="text-2xl font-bold tabular-nums leading-none {{ $tab['tone'] }}">{{ $tab['count'] }}</span>
            <span class="text-sm font-semibold text-secondary-dark dark:text-ink">{{ $tab['label'] }}</span>
            @if ($focus === $tab['key'])
                <x-ui.focus-clear-mark :label="__('indicators.focus_press_again')" data-test="focus-tab-clear-{{ $tab['key'] }}" />
            @endif
        </button>
    @endforeach

    @if ($filtersAreActive)
        <flux:button size="sm" variant="ghost" class="self-center text-secondary-medium! dark:text-ink-muted!" wire:click="clearFilters">{{ __('common.reset_filters') }}</flux:button>
    @endif
</div>

@if ($focus !== '' && isset($focusCounts[$focus]))
    <x-ui.focus-showing
        :text="__('indicators.focus_showing', ['shown' => $focusCounts[$focus], 'total' => $focusCounts['total']])"
        action="setFocus('{{ $focus }}')"
        :label="__('indicators.focus_show_all')"
        data-test="focus-showing"
    />
@endif
