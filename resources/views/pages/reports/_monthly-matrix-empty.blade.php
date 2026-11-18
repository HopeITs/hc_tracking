{{--
    The empty result of the Monthly Matrix, both tabs (quality-bar §3): it names
    the filter that emptied the page (emptyReason) and offers Reset filters.
    Included by ⚡monthly-matrix.blade.php and _monthly-matrix-activities with
    $emptyIcon and $emptyHeading (the heading when no single filter is to blame);
    $filterLabels and $filtersAreActive come from the page's top block.
--}}
@php
    $emptyReason = $this->emptyReason;
    $emptyFilterLabel = $emptyReason === null ? null : ($filterLabels[$emptyReason] ?? $emptyReason);

    if ($emptyReason === 'mine') {
        $emptyStateHeading = __('reports.matrix.no_assigned_projects');
        $emptyStateBody = __('reports.matrix.no_assigned_projects_hint');
    } elseif ($emptyFilterLabel !== null) {
        $emptyStateHeading = __('reports.matrix.empty_by_filter', ['filter' => $emptyFilterLabel]);
        $emptyStateBody = __('reports.matrix.empty_by_filter_hint');
    } else {
        $emptyStateHeading = $emptyHeading;
        $emptyStateBody = $emptyBody ?? __('common.change_the_search_or_filters_and_try_again');
    }
@endphp

<x-ui.panel data-matrix-empty :data-matrix-empty-reason="$emptyReason">
    <x-ui.empty-state :icon="$emptyIcon" :heading="$emptyStateHeading" :body="$emptyStateBody">
        <x-slot:action>
            @if ($filtersAreActive)
                <x-ui.button tone="primary" icon="arrow-path" wire:click="clearFilters" data-test="matrix-reset-filters">{{ __('reports.matrix.reset_filters') }}</x-ui.button>
            @endif
        </x-slot:action>
    </x-ui.empty-state>
</x-ui.panel>
