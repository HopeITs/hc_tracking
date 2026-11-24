{{--
    The month-box legend of the Monthly Matrix: one swatch drawn like the box
    it explains, with its words, so colour never stands alone. Included by
    _monthly-matrix-toolbar.blade.php.
--}}
<ul class="mm-legend" data-matrix-legend aria-label="{{ __('reports.matrix.legend') }}">
    @foreach (['done', 'waiting', 'missing', 'open', 'upcoming', 'none'] as $kind)
        <li><span @class(['mm-swatch', 'mm-cell-'.$kind]) aria-hidden="true"></span>{{ __('reports.matrix.legend_'.$kind) }}</li>
    @endforeach
</ul>
