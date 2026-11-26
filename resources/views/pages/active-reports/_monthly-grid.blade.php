{{-- Monthly reports: places × at most six months, one page of places at a time. Never scrolls sideways. --}}
@php
    $grid = $this->grid;
    $visible = $this->visibleMonths;
    $pageOf = $this->placePage;
    $today = \Carbon\CarbonImmutable::today();
    $currentMonth = $today->format('Y-m');
    $lastMonth = $today->startOfMonth()->subMonth()->format('Y-m');
    $monthOf = fn (string $month): \Carbon\CarbonImmutable => \Carbon\CarbonImmutable::createFromFormat('!Y-m', $month);
    $cellsIn = fn (string $month): array => array_values(array_filter(array_map(fn (array $place) => $place['cells'][$month] ?? null, $grid['places'])));
    $last = $cellsIn($lastMonth);
    $lastIn = count(array_filter($last, fn ($row): bool => in_array($row->state(), ['wait', 'done'], true)));
    $currentOpen = count(array_filter($cellsIn($currentMonth), fn ($row): bool => $row->state() !== 'done'));
    $canEarlier = $visible !== [] && $visible[0] !== ($grid['months'][0] ?? null);
    $canLater = $visible !== [] && end($visible) !== end($grid['months']);
    $chips = [
        '' => [__('active_reports.chip_open'), $grid['counts']['open'], null],
        'needs' => [__('active_reports.chip_needs'), $grid['counts']['needs'], null],
        'late' => [__('active_reports.chip_late'), $grid['counts']['late'], 'late'],
        'wait' => [__('active_reports.chip_wait'), $grid['counts']['wait'], 'wait'],
        'done' => [__('active_reports.chip_validated'), $grid['counts']['done'], 'done'],
    ];
@endphp

<div class="rt-summary">
    <div class="rt-sum">
        <small>{{ __('active_reports.sum_last_month', ['month' => $monthOf($lastMonth)->translatedFormat('F')]) }}</small>
        <b class="dk-num">{{ __('active_reports.x_of_y', ['x' => $lastIn, 'y' => count($last)]) }}</b>
        <span>{{ __('active_reports.places_submitted') }}</span>
        <span class="rt-progress"><i style="width: {{ count($last) > 0 ? round($lastIn / count($last) * 100) : 0 }}%"></i></span>
    </div>
    <button type="button" class="rt-sum is-alert" wire:click="setChip('late')" aria-pressed="{{ $show === 'late' ? 'true' : 'false' }}">
        <small>{{ __('active_reports.chip_late') }}</small>
        <b class="dk-num">{{ $grid['counts']['late'] }}</b>
        <span>{{ __('active_reports.sum_late') }}</span>
    </button>
    <button type="button" class="rt-sum" wire:click="setChip('wait')" aria-pressed="{{ $show === 'wait' ? 'true' : 'false' }}">
        <small>{{ __('active_reports.chip_wait') }}</small>
        <b class="dk-num">{{ $grid['counts']['wait'] }}</b>
        <span>{{ __('active_reports.sum_wait') }}</span>
    </button>
    <div class="rt-sum">
        <small>{{ __('active_reports.sum_this_month', ['month' => $today->translatedFormat('F')]) }}</small>
        <b class="dk-num">{{ $currentOpen }}</b>
        <span>{{ __('active_reports.sum_this_month_body') }}</span>
    </div>
</div>

@if ($grid['places'] === [])
    <x-ui.empty-state icon="document-check" :heading="$search !== '' || $program !== '' ? __('active_reports.no_matching') : __('active_reports.none_monthly')" :body="$search !== '' || $program !== '' ? __('common.change_the_search_or_filters_and_try_again') : __('active_reports.none_monthly_body')" />
@else
    <div class="rt-panel">
        <div class="rt-toolbar">
            <div class="rt-chips" role="group" aria-label="{{ __('active_reports.show') }}">
                @foreach ($chips as $value => [$label, $number, $dot])
                    <button type="button" class="rt-chip" wire:click="setChip('{{ $value }}')" aria-pressed="{{ $show === $value ? 'true' : 'false' }}" data-tracker-chip="{{ $value === '' ? 'open' : $value }}">
                        @if ($dot)<span class="rt-dot" data-state="{{ $dot }}"></span>@endif
                        {{ $label }}<span class="rt-chip-n dk-num">{{ $number }}</span>
                    </button>
                @endforeach
            </div>
            @if (count($grid['months']) > count($visible))
                <div class="rt-monthnav" data-tracker-monthnav>
                    <button type="button" class="rt-iconbtn" wire:click="shiftMonths(1)" @disabled(! $canEarlier) aria-label="{{ __('active_reports.earlier_months') }}"><x-desk.icon name="back" class="dk-flip" /></button>
                    <span class="dk-num">{{ __('active_reports.months_shown', ['from' => $monthOf($visible[0])->translatedFormat(__('formats.month_short')), 'to' => $monthOf(end($visible))->translatedFormat(__('formats.month_short').' Y')]) }}</span>
                    <button type="button" class="rt-iconbtn" wire:click="shiftMonths(-1)" @disabled(! $canLater) aria-label="{{ __('active_reports.later_months') }}"><x-desk.icon name="back" class="rt-fwd" /></button>
                </div>
            @endif
        </div>

        @if ($pageOf['places'] === [])
            <p class="rt-empty-line">{{ __('active_reports.no_place_matches_chip') }}</p>
        @else
            <table class="rt-grid" style="--rt-cols: {{ count($visible) }}">
                <caption class="dk-sr">{{ __('active_reports.kind_monthly') }}</caption>
                <colgroup><col class="rt-col-place">@foreach ($visible as $month)<col>@endforeach</colgroup>
                <thead>
                    <tr>
                        <th scope="col" class="rt-place">{{ __('active_reports.place') }}</th>
                        @foreach ($visible as $month)
                            <th scope="col" @class(['is-now' => $month === $currentMonth])>
                                {{ $monthOf($month)->translatedFormat(__('formats.month_short')) }}
                                <span class="rt-col-due">{{ __('active_reports.due_on', ['date' => $monthOf($month)->addMonth()->translatedFormat(__('formats.date_short'))]) }}</span>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($pageOf['places'] as $place)
                        <tr wire:key="tracker-place-{{ $place['templateId'] }}" data-tracker-place="{{ $place['templateId'] }}">
                            <th scope="row" class="rt-place">
                                <b dir="auto" title="{{ $place['name'] }}">{{ $place['name'] }}</b>
                                <span class="rt-place-meta" dir="auto" title="{{ $place['context'] }} · {{ __('active_reports.prepared_by') }}: {{ $place['owner'] ?? __('active_reports.not_assigned') }}">
                                    @if ($place['behind'] > 1)
                                        <em class="rt-behind">{{ trans_choice('active_reports.months_behind', $place['behind'], ['count' => $place['behind']]) }}</em>
                                    @endif
                                    {{ $place['owner'] ?? __('active_reports.not_assigned') }} · {{ $place['context'] }}
                                </span>
                            </th>
                            @foreach ($visible as $month)
                                @php $row = $place['cells'][$month] ?? null; @endphp
                                <td @class(['is-now' => $month === $currentMonth])>
                                    @if ($row === null)
                                        <span class="rt-cell" data-state="none" aria-label="{{ __('active_reports.no_report') }}">—</span>
                                    @else
                                        @php
                                            $state = $row->state();
                                            $lateDays = $row->due !== null && $row->due->lessThan($today) ? (int) $row->due->diffInDays($today) : 0;
                                            $label = match ($state) {
                                                'late' => trans_choice('active_reports.cell_late_short', $lateDays, ['count' => $lateDays]),
                                                'returned' => __('active_reports.cell_returned'),
                                                'wait' => __('active_reports.cell_wait'),
                                                'draft' => __('active_reports.cell_draft'),
                                                default => __('active_reports.cell_done'),
                                            };
                                            $short = match ($state) {
                                                'late' => trans_choice('active_reports.cell_late_tiny', $lateDays, ['count' => $lateDays]),
                                                'wait' => __('active_reports.cell_wait_short'),
                                                default => $label,
                                            };
                                        @endphp
                                        <a href="{{ $row->url }}" wire:navigate @class(['rt-cell', 'is-dim' => ! $this->keepsCell($row)]) data-state="{{ $state }}" data-tracker-cell="{{ $row->key() }}" title="{{ $state === 'late' ? trans_choice('active_reports.cell_late', $lateDays, ['count' => $lateDays]) : $label }}" aria-label="{{ $place['name'] }}, {{ $monthOf($month)->translatedFormat('F Y') }}: {{ $label }}">
                                            @if ($state === 'done')<x-desk.icon name="check" />@else {{ $short }} @endif
                                        </a>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <div class="rt-foot">
            <div class="rt-legend" aria-hidden="true">
                <span><i class="rt-cell" data-state="late">5d</i> {{ __('active_reports.legend_late') }}</span>
                <span><i class="rt-cell" data-state="returned">{{ __('active_reports.cell_returned') }}</i> {{ __('active_reports.legend_returned') }}</span>
                <span><i class="rt-cell" data-state="wait">{{ __('active_reports.cell_wait_short') }}</i> {{ __('active_reports.legend_wait') }}</span>
                <span><i class="rt-cell" data-state="draft">{{ __('active_reports.cell_draft') }}</i> {{ __('active_reports.legend_draft') }}</span>
                <span><i class="rt-cell" data-state="done"><x-desk.icon name="check" /></i> {{ __('active_reports.legend_done') }}</span>
            </div>
            @include('pages.active-reports._pager', ['pager' => $pageOf])
        </div>
    </div>
@endif
