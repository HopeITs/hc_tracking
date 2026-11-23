{{--
    The Monthly Matrix toolbar: group by proposal, center or indicator (all
    centers added up), the persons-with-disability switch, the pressed summary
    filter as a chip that clears it, then the legend. Included by
    ⚡monthly-matrix.blade.php.
--}}
@php
    $groupOptions = [
        'proposal' => ['label' => __('reports.matrix.group_proposal'), 'test' => 'matrix-group-proposal'],
        'center' => ['label' => __('reports.matrix.view_rows'), 'test' => 'matrix-view-rows'],
        'indicator' => ['label' => __('reports.matrix.view_grouped'), 'test' => 'matrix-view-grouped'],
    ];
    $focusLabels = [
        'pace' => __('reports.matrix.summary_pace'),
        'behind' => __('reports.matrix.summary_behind'),
        'waiting' => __('reports.matrix.summary_waiting'),
        'missing' => __('reports.matrix.summary_missing'),
    ];
@endphp

<div class="mm-toolbar" data-matrix-toolbar>
    <div class="mm-toolbar-start">
        <span class="mm-toolbar-label" id="matrix-group-label">{{ __('reports.matrix.group_by') }}</span>
        <div class="dk-seg" role="group" aria-labelledby="matrix-group-label">
            @foreach ($groupOptions as $key => $option)
                <button type="button" wire:click="groupBy('{{ $key }}')" aria-pressed="{{ $this->resolvedGroup === $key ? 'true' : 'false' }}" data-test="{{ $option['test'] }}">{{ $option['label'] }}</button>
            @endforeach
        </div>

        <button type="button" class="mm-switch" wire:click="$toggle('pwd')" aria-pressed="{{ $pwd ? 'true' : 'false' }}" data-test="matrix-pwd">
            <span class="mm-switch-track" aria-hidden="true"><span></span></span>
            {{ __('reports.matrix.show_pwd') }}
        </button>

        @isset($focusLabels[$focus])
            <button type="button" class="mm-chip" wire:click="setFocus('{{ $focus }}')" data-test="matrix-focus-clear">
                {{ __('reports.matrix.focus_only', ['name' => $focusLabels[$focus]]) }}
                <flux:icon.x-mark variant="micro" class="size-4" aria-hidden="true" />
                <span class="sr-only">{{ __('reports.matrix.clear_focus') }}</span>
            </button>
        @endisset
    </div>

    @include('pages.reports._monthly-matrix-legend')
</div>
