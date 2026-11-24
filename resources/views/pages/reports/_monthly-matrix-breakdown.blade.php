{{--
    The by-sex breakdown of one matrix row (D-50): male, female and their
    total by month, each followed by its "of whom persons with disability"
    row when the pwd switch is on. Included by _monthly-matrix-fold.blade.php,
    so $row, $windowMonths and $monthFormat are in scope. Every figure
    arrives computed.
--}}
@use('App\Enums\IndicatorMeasure')

@php
    $breakdownSuffix = $row['breakdown_kind'] === 'ratio' && $row['measure'] === IndicatorMeasure::Percentage ? '%' : '';
    $subRows = [];
    foreach (['male', 'female', 'total'] as $category) {
        $subRows[] = ['key' => $category, 'label' => __('reports.matrix.sex.'.$category), 'figures' => $row['breakdown'][$category], 'pwd' => false];

        if ($this->pwd) {
            $subRows[] = ['key' => $category.'-pwd', 'label' => __('reports.matrix.of_whom_pwd'), 'figures' => $row['breakdown'][$category]['pwd'], 'pwd' => true];
        }
    }
@endphp

@if ($row['breakdown_kind'] === 'fixed')
    <p class="mm-panel-text">{{ __('reports.matrix.fixed_counts_note') }}</p>
@endif

<div class="mm-bd-scroll">
    <table class="mm-bd">
        <thead>
            <tr>
                <th scope="col"><span class="sr-only">{{ __('reports.matrix.breakdown') }}</span></th>
                @foreach ($windowMonths as $month)
                    <th scope="col">{{ $month->translatedFormat($monthFormat) }}</th>
                @endforeach
                <th scope="col">{{ __('reports.matrix.total') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($subRows as $sub)
                <tr @class(['mm-bd-pwd' => $sub['pwd'], 'mm-bd-total' => $sub['key'] === 'total']) data-matrix-breakdown="{{ $sub['key'] }}">
                    <th scope="row">{{ $sub['label'] }}</th>
                    @foreach ($windowMonths as $month)
                        @php
                            $figure = $sub['figures']['cells'][$month->format('Y-m')] ?? null;
                        @endphp
                        <td>@if ($figure !== null)<span dir="ltr">{{ $this->matrixNumber($figure) }}{{ $breakdownSuffix }}</span>@endif</td>
                    @endforeach
                    <td class="mm-bd-sum">@if ($sub['figures']['total'] !== null)<span dir="ltr">{{ $this->matrixNumber($sub['figures']['total']) }}{{ $breakdownSuffix }}</span>@endif</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
