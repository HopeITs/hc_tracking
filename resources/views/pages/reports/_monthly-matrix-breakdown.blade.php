{{--
    The by-sex breakdown rows of one matrix row (D-50): male, female and their
    total, each followed by its "of whom persons with disability" row when the
    pwd switch is on. Included by _monthly-matrix-table.blade.php inside the
    row's own <tbody>, so $row, $matrix, $bodyCell, $stickyCode and $stickyName
    are in scope. Every figure arrives computed; the rows start hidden and
    the row's Alpine `open` flag shows them.
--}}
@use('App\Enums\IndicatorMeasure')

@php
    $suffix = $row['breakdown_kind'] === 'ratio' && $row['measure'] === IndicatorMeasure::Percentage ? '%' : '';
    $subRows = [];
    foreach (['male', 'female', 'total'] as $category) {
        $subRows[] = ['key' => $category, 'label' => __('reports.matrix.sex.'.$category), 'figures' => $row['breakdown'][$category], 'pwd' => false];

        if ($this->pwd) {
            $subRows[] = ['key' => $category.'-pwd', 'label' => __('reports.matrix.of_whom_pwd'), 'figures' => $row['breakdown'][$category]['pwd'], 'pwd' => true];
        }
    }
@endphp

@foreach ($subRows as $sub)
    <tr x-show="open" x-cloak data-matrix-breakdown="{{ $sub['key'] }}">
        <td class="{{ $bodyCell }} {{ $stickyCode }} bg-white dark:bg-surface-1"></td>
        <td class="{{ $bodyCell }} {{ $stickyName }} bg-white dark:bg-surface-1">
            <span @class(['block text-xs', 'ps-4 text-secondary-medium dark:text-ink-muted' => $sub['pwd'], 'ps-2 font-medium' => ! $sub['pwd']])>{{ $sub['label'] }}</span>
        </td>
        <td class="{{ $bodyCell }}" colspan="4">
            @if ($loop->first && $row['breakdown_kind'] === 'fixed')
                <span class="text-[11px] text-secondary-medium dark:text-ink-muted">{{ __('reports.matrix.fixed_counts_note') }}</span>
            @endif
        </td>
        <td class="{{ $bodyCell }}"></td>
        @foreach ($matrix['months'] as $month)
            @php $figure = $sub['figures']['cells'][$month->format('Y-m')] ?? null; @endphp
            <td class="{{ $bodyCell }} text-end text-xs tabular-nums">
                @if ($figure !== null)<span dir="ltr">{{ $this->matrixNumber($figure) }}{{ $suffix }}</span>@endif
            </td>
        @endforeach
        <td class="{{ $bodyCell }} text-end text-xs font-semibold tabular-nums">
            @if ($sub['figures']['total'] !== null)<span dir="ltr">{{ $this->matrixNumber($sub['figures']['total']) }}{{ $suffix }}</span>@endif
        </td>
        <td class="{{ $bodyCell }}"></td>
    </tr>
@endforeach
