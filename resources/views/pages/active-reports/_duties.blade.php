{{-- My reporting duties: an inbox in three folding sections (fill in, check, send), late first, ten lines a page. --}}
@php
    $duties = $this->duties;
    $today = \Carbon\CarbonImmutable::today();
    $monthOf = fn (string $month): string => \Carbon\CarbonImmutable::createFromFormat('!Y-m', $month)->translatedFormat(__('formats.month_short'));
    $monthLong = fn (string $month): string => \Carbon\CarbonImmutable::createFromFormat('!Y-m', $month)->translatedFormat('F Y');
    $groups = [
        'fill' => [__('active_reports.group_fill'), __('active_reports.group_fill_why'), 'edit'],
        'check' => [__('active_reports.group_check'), __('active_reports.group_check_why'), 'check'],
        'send' => [__('active_reports.group_send'), __('active_reports.group_send_why'), 'file'],
    ];
    $weekAhead = $today->addDays(7);
    $pageSize = 10;
    $allLines = [...$duties['fill'], ...$duties['check'], ...$duties['send']];
    $thisWeek = count(array_filter($allLines, fn ($line): bool => $line->due->greaterThanOrEqualTo($today) && $line->due->lessThanOrEqualTo($weekAhead)));
@endphp

<div class="rt-summary">
    <div class="rt-sum is-alert">
        <small>{{ __('active_reports.chip_late') }}</small>
        <b class="dk-num">{{ $duties['late'] }}</b>
        <span>{{ __('active_reports.sum_duties_late') }}</span>
    </div>
    <div class="rt-sum">
        <small>{{ __('active_reports.sum_this_week') }}</small>
        <b class="dk-num">{{ $thisWeek }}</b>
        <span>{{ __('active_reports.until', ['date' => $weekAhead->translatedFormat(__('formats.date_short'))]) }}</span>
    </div>
    <div class="rt-sum">
        <small>{{ __('active_reports.sum_all_to_do') }}</small>
        <b class="dk-num">{{ $duties['total'] }}</b>
        <span>{{ __('active_reports.sum_all_to_do_body') }}</span>
    </div>
</div>

@if ($duties['total'] === 0)
    <x-ui.empty-state icon="check-circle" :heading="$search !== '' ? __('active_reports.no_matching') : __('active_reports.none_needs')" :body="$search !== '' ? __('common.change_the_search_or_filters_and_try_again') : __('active_reports.none_needs_body')" />
@else
    <div class="rt-stack">
        @foreach ($groups as $groupKey => [$groupTitle, $groupWhy, $groupIcon])
            @continue ($duties[$groupKey] === [])
            @php
                $lines = $duties[$groupKey];
                $lateHere = count(array_filter($lines, fn ($line): bool => $line->late));
                $pages = (int) ceil(count($lines) / $pageSize);
            @endphp

            <section class="rt-fold" x-data="{ open: false, page: 1, pages: {{ $pages }} }" wire:key="tracker-group-{{ $groupKey }}" data-tracker-group="{{ $groupKey }}">
                <button type="button" class="rt-fold-h" x-on:click="open = ! open" x-bind:aria-expanded="open">
                    <span class="rt-fold-icon"><x-desk.icon :name="$groupIcon" /></span>
                    <span class="rt-fold-title"><b>{{ $groupTitle }}</b><span>{{ $groupWhy }}</span></span>
                    <span class="rt-fold-counts">
                        @if ($lateHere > 0)<span class="rt-badge is-late dk-num">{{ __('active_reports.n_late', ['count' => $lateHere]) }}</span>@endif
                        <span class="rt-badge dk-num">{{ count($lines) }}</span>
                    </span>
                    <x-desk.icon name="chev" class="rt-fold-chev" />
                </button>

                <div class="rt-fold-body" x-show="open" x-collapse x-cloak>
                    @foreach ($lines as $line)
                        @php
                            $months = $line->months;
                            $detail = match (true) {
                                count($months) > 1 => trans_choice('active_reports.months_open', count($months), ['count' => count($months), 'from' => $monthOf($months[0]), 'to' => $monthOf($months[count($months) - 1])]),
                                count($months) === 1 => $monthLong($months[0]).' · '.$line->context,
                                default => $line->context,
                            };
                            $days = (int) abs($today->diffInDays($line->due));
                            $when = match (true) {
                                $line->due->greaterThanOrEqualTo($today) => trans_choice('active_reports.due_in_days', $days, ['count' => $days]),
                                $line->late => trans_choice('active_reports.days_late', $days, ['count' => $days]),
                                default => trans_choice('active_reports.days_waiting', $days, ['count' => $days]),
                            };
                            $action = match (true) {
                                $groupKey === 'check' => __('active_reports.do_validate'),
                                $groupKey === 'send' => __('active_reports.do_prepare'),
                                count($months) > 1 => __('active_reports.do_open_oldest'),
                                default => __('active_reports.do_fill_in'),
                            };
                            $onPage = intdiv($loop->index, $pageSize) + 1;
                        @endphp
                        <div @class(['rt-duty', 'is-late' => $line->late]) wire:key="tracker-duty-{{ $line->key }}" data-tracker-duty="{{ $line->key }}" @if ($onPage > 1) x-show="page === {{ $onPage }}" x-cloak @else x-show="page === 1" @endif>
                            <span class="rt-duty-main">
                                <span class="rt-duty-line1"><span class="rt-tag">{{ $line->type->label() }}</span><b dir="auto">{{ $line->name }}</b></span>
                                <span class="rt-duty-detail" dir="auto">{{ $detail }}</span>
                            </span>
                            <span @class(['rt-when', 'is-late' => $line->late])>
                                <b>{{ $when }}</b>
                                <small>{{ $line->due->translatedFormat(__('formats.date_short')) }}</small>
                            </span>
                            <a href="{{ $line->url }}" wire:navigate @class(['dk-btn', 'rt-duty-go', 'dk-primary' => $line->late])>{{ $action }}</a>
                        </div>
                    @endforeach

                    @if ($pages > 1)
                        <nav class="rt-pager is-mini" aria-label="{{ $groupTitle }}">
                            <span class="rt-pager-count dk-num" x-text="@js(__('active_reports.page_of', ['page' => '__P__', 'pages' => $pages])).replace('__P__', page)"></span>
                            <span class="rt-pager-pages">
                                <button type="button" class="rt-iconbtn" x-on:click="page--" x-bind:disabled="page === 1" aria-label="{{ __('active_reports.previous') }}"><x-desk.icon name="back" class="dk-flip" /></button>
                                <button type="button" class="rt-iconbtn" x-on:click="page++" x-bind:disabled="page === pages" aria-label="{{ __('active_reports.next') }}"><x-desk.icon name="back" class="rt-fwd" /></button>
                            </span>
                        </nav>
                    @endif
                </div>
            </section>
        @endforeach
    </div>
@endif
