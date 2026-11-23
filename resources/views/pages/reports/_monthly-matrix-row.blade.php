{{--
    One Monthly Matrix row (a place and library indicator, or one indicator
    across centers): the indicator, its progress against pace, a box per
    month, and a fold with the breakdown, the latest reports and the next
    step. Every figure arrives computed (BuildsMonthlyMatrix,
    ReadsMatrixProgress); nothing here adds up cells. Included by
    _monthly-matrix-cards.blade.php inside its loop over $row.
--}}
@use('App\Enums\IndicatorMeasure')

@php
    $progress = $this->rowProgress[$row['key']];
    $isPercent = $row['measure'] === IndicatorMeasure::Percentage;
    $suffix = $isPercent ? '%' : '';
    $isCount = $row['measure'] === IndicatorMeasure::Numeric;
    $rowName = app()->getLocale() === 'ar' ? ($row['name_ar'] ?? $row['name_en']) : $row['name_en'];
    $barWidth = $progress['reached'] === null ? 0 : max(2, min(100, $progress['reached']));
    $tick = $progress['expected'] === null || $row['target'] === null || (float) $row['target'] <= 0.0 ? null : min(100, $progress['expected'] / (float) $row['target'] * 100);
    $pwdCells = $pwd && $row['breakdown'] !== null ? ($row['breakdown']['total']['pwd']['cells'] ?? []) : [];
@endphp

<article class="mm-row" wire:key="{{ $row['key'] }}" x-data="{ open: false }" data-matrix-row="{{ $row['key'] }}">
    <div class="mm-line">
        <div class="mm-ind">
            <span class="mm-ind-meta">
                <span class="mm-code" dir="ltr">{{ $row['code'] }}</span>
                <span>{{ $row['measure']->label() }}@if ($isCount && filled($row['unit'])) · <span dir="auto">{{ $row['unit'] }}</span>@endif</span>
                @if ($row['is_cumulative'])
                    <span>· {{ __('reports.matrix.cumulative') }}</span>
                @endif
                @unless ($row['is_active'])
                    <span class="mm-muted-tag"><flux:icon.pause-circle variant="micro" class="size-3.5" aria-hidden="true" />{{ __('reports.matrix.inactive') }}</span>
                @endunless
            </span>
            <span class="mm-ind-name" dir="auto">{{ $rowName }}</span>
            <span class="mm-ind-ctx">
                @if ($row['is_group'])
                    {{ __('reports.matrix.centers_count', ['count' => $row['centers_count']]) }}
                @else
                    <span dir="auto" data-matrix-place>{{ $row['center'] }}</span>@if ($row['city'] !== null) · <span dir="auto">{{ $row['city'] }}</span>@endif
                @endif
            </span>
        </div>

        <div class="mm-prog">
            <span class="mm-prog-figure">
                <b dir="ltr" class="dk-num" data-matrix-total>{{ $row['total'] === null ? '—' : $this->matrixNumber($row['total']).$suffix }}</b>
                <span data-matrix-target>
                    @if ($row['target'] === null)
                        {{ __('reports.matrix.no_target') }}
                    @else
                        {!! __('reports.matrix.of_target', ['target' => '<span dir="ltr">'.e($this->matrixNumber($row['target']).$suffix).'</span>']) !!}
                    @endif
                </span>
            </span>
            @if ($row['target'] !== null)
                <span class="mm-bar" role="img" aria-label="{{ $progress['reached'] === null ? __('reports.matrix.no_achieved') : __('reports.matrix.reached_share', ['percent' => $this->matrixNumber(round($progress['reached']))]) }}">
                    <span @class(['mm-bar-fill', 'mm-bar-behind' => $progress['behind']]) style="width: {{ $barWidth }}%"></span>
                    @if ($tick !== null)
                        <span class="mm-bar-tick" style="inset-inline-start: calc({{ $tick }}% - 1px)"></span>
                    @endif
                </span>
            @endif
            <span @class(['mm-pace', 'mm-pace-behind' => $progress['behind']]) data-matrix-pace>
                @if ($progress['expected'] === null)
                    {{ $row['target'] === null ? '' : __('reports.matrix.no_validated_yet') }}
                @elseif ($progress['behind'])
                    {{ $isCount ? __('reports.matrix.behind_by', ['gap' => $this->matrixNumber(round($progress['gap']))]) : __('reports.matrix.below_target_by', ['gap' => $this->matrixNumber(round($progress['gap'], 1)).$suffix]) }}
                @else
                    {{ __('reports.matrix.on_pace') }}
                @endif
            </span>
            <span class="mm-prog-note" data-matrix-achieved>
                @if ($row['achieved'] !== null)
                    {{ __('reports.matrix.reached_share', ['percent' => $this->matrixNumber($row['achieved'])]) }}
                @endif
            </span>
            @if ($progress['waiting'] > 0)
                <span class="mm-prog-note">{{ trans_choice('reports.matrix.waiting_note', $progress['waiting'], ['count' => $progress['waiting']]) }}</span>
            @endif
            @if ($row['excluded'] > 0)
                <span class="mm-prog-note">{{ __('reports.matrix.not_poolable', ['count' => $row['excluded']]) }}</span>
            @endif
            @if (($row['shared_centers'] ?? null) !== null)
                <span class="mm-prog-note" data-matrix-shared><flux:icon.share variant="micro" class="size-3.5" aria-hidden="true" />{{ __('reports.matrix.shared_with_centers', ['count' => $row['shared_centers']]) }}</span>
            @endif
            @if ($row['is_group'] && $row['is_cumulative'])
                <span class="mm-prog-note">{{ __('reports.matrix.cumulative_group_note') }}</span>
            @endif
        </div>

        <div class="mm-strip">
            <ol class="mm-months" style="--mm-cols: {{ count($windowMonths) }}" aria-label="{{ $row['code'] }}">
                @foreach ($windowMonths as $month)
                    @php
                        $ym = $month->format('Y-m');
                        $cell = $row['cells'][$ym];
                        $kind = $progress['kinds'][$ym];
                        $cellUrl = $this->cellReportUrl($row, $month);
                        $monthLabel = $month->translatedFormat($monthFormat);
                        $hasValue = in_array($kind, ['done', 'waiting'], true);
                        $valueText = $cell['not_measured'] ? __('reports.matrix.not_measured') : $this->matrixNumber($cell['value']).$suffix;
                        $fill = $kind === 'done' && $cell['value'] !== null && $progress['monthlyShare'] !== null && $progress['monthlyShare'] > 0.0
                            ? min(100, $cell['value'] / $progress['monthlyShare'] * 100)
                            : null;
                        $pwdFigure = $pwdCells[$ym] ?? null;
                    @endphp
                    <li @class(['mm-cell', 'mm-cell-'.$kind]) title="{{ $monthLabel }} · {{ $cell['state']->label() }}" data-matrix-cell="{{ $cell['state']->value }}">
                        @if ($cellUrl !== null)
                            <a href="{{ $cellUrl }}" wire:navigate class="mm-cell-in" data-matrix-cell-link>
                        @else
                            <span class="mm-cell-in">
                        @endif
                            @switch($kind)
                                @case('done')
                                @case('waiting')
                                    <span class="mm-cell-value" dir="ltr">{{ $valueText }}</span>
                                    @if ($pwdFigure !== null)
                                        <span class="mm-cell-sub">{{ __('reports.matrix.pwd_short', ['count' => $this->matrixNumber($pwdFigure)]) }}</span>
                                    @elseif ($kind === 'waiting')
                                        <span class="mm-cell-sub">{{ __('reports.matrix.cell_waiting') }}</span>
                                    @endif
                                    @if ($fill !== null)
                                        <span class="mm-cell-bar"><span @class(['mm-cell-bar-low' => $fill < 70]) style="width: {{ $fill }}%"></span></span>
                                    @endif
                                    @break
                                @case('missing')
                                    <span class="mm-cell-value" aria-hidden="true">!</span>
                                    <span class="mm-cell-sub">{{ __('reports.matrix.cell_missing') }}</span>
                                    @break
                                @case('open')
                                    <span class="mm-cell-sub">{{ __('reports.matrix.cell_open') }}</span>
                                    @break
                                @default
                                    <span aria-hidden="true">·</span>
                            @endswitch
                            <span class="sr-only">{{ $monthLabel }}: {{ $cell['state']->label() }}</span>
                        @if ($cellUrl !== null)
                                {{-- Added to the figure, not in place of it: an aria-label would hide the value from screen readers. --}}
                                <span class="sr-only">{{ __('reports.matrix.open_month_list', ['month' => $monthLabel]) }}</span>
                            </a>
                        @else
                            </span>
                        @endif
                    </li>
                @endforeach
            </ol>
        </div>

        <button type="button" class="mm-fold-btn" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" aria-expanded="false" aria-label="{{ __('reports.matrix.toggle_row', ['code' => $row['code']]) }}" data-matrix-expand>
            <flux:icon.chevron-down class="mm-chev size-[18px]" x-bind:class="open ? 'rotate-180' : ''" aria-hidden="true" />
        </button>
    </div>

    <div class="mm-fold" x-show="open" x-cloak>
        @include('pages.reports._monthly-matrix-fold')
    </div>
</article>
