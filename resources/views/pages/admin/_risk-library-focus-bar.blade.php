{{--
    The three plain answers at the top of admin/risk-library: how many library
    risks proposals already pick from (in use), how many are on but nobody has
    picked yet (not used yet), and how many are switched off. Each is a button
    that narrows the list; pressing it again shows everything. The counts are
    disjoint and read under the search. Included by ⚡risk-library.blade.php,
    which defines $focusCounts and $filtersAreActive.
--}}
@php
    $tabs = [
        ['key' => 'inuse', 'tone' => 'text-status-good'],
        ['key' => 'unused', 'tone' => 'text-secondary-dark dark:text-ink'],
        ['key' => 'off', 'tone' => 'text-secondary-medium dark:text-ink-muted'],
    ];
@endphp

<div class="flex flex-wrap items-stretch gap-2" data-test="risk-library-focus-tabs">
    @foreach ($tabs as $tab)
        <button
            type="button"
            wire:click="setFocus('{{ $tab['key'] }}')"
            wire:key="focus-tab-{{ $tab['key'] }}"
            aria-pressed="{{ $focus === $tab['key'] ? 'true' : 'false' }}"
            data-test="focus-tab-{{ $tab['key'] }}"
            @class([
                'flex min-h-14 flex-1 basis-40 cursor-pointer items-center gap-3 rounded-xl border px-4 py-2 text-start transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium',
                'border-secondary-dark bg-white shadow-card ring-1 ring-secondary-dark dark:border-ink dark:bg-surface-2 dark:ring-ink' => $focus === $tab['key'],
                'border-secondary-soft bg-white hover:border-secondary-light dark:border-line dark:bg-surface-1' => $focus !== $tab['key'],
            ])
        >
            <span class="text-2xl font-bold leading-none tabular-nums {{ $tab['tone'] }}">{{ $focusCounts[$tab['key']] }}</span>
            <span class="text-sm font-semibold text-secondary-dark dark:text-ink">{{ __('risk_library.status_'.$tab['key']) }}</span>
            @if ($focus === $tab['key'])
                <x-ui.focus-clear-mark :label="__('risk_library.focus_press_again')" data-test="focus-tab-clear-{{ $tab['key'] }}" />
            @endif
        </button>
    @endforeach

    @if ($filtersAreActive)
        <flux:button size="sm" variant="ghost" class="self-center text-secondary-medium! dark:text-ink-muted!" wire:click="clearFilters" data-test="reset-filters">{{ __('common.reset_filters') }}</flux:button>
    @endif
</div>

@if ($focus !== '' && isset($focusCounts[$focus]))
    <x-ui.focus-showing
        :text="__('risk_library.focus_showing', ['shown' => $focusCounts[$focus], 'total' => $focusCounts['total']])"
        action="setFocus('{{ $focus }}')"
        :label="__('risk_library.focus_show_all')"
        data-test="focus-showing"
    />
@endif
