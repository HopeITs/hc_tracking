{{-- Donor reports grouped by donor: one folding card each, overdue first, then by due date, sent last. Eight donors a page, six reports a donor page. No money is read or shown. --}}
@php
    $lanes = $this->donorLanes;
    $groups = $this->donorGroups;
    $today = \Carbon\CarbonImmutable::today();
    $openDonor = $counts['donor'];
    $soonNext = $lanes['soon'][0] ?? null;
    $donorPageSize = 8;
    $rowPageSize = 6;
    $donorPages = max(1, (int) ceil(count($groups) / $donorPageSize));
    $laneNames = ['overdue' => __('active_reports.lane_overdue'), 'soon' => __('active_reports.lane_soon'), 'later' => __('active_reports.lane_later'), 'submitted' => __('active_reports.lane_submitted')];
    $countdown = function ($row, string $lane) use ($today): string {
        if ($row->due === null) {
            return '';
        }

        if ($lane === 'submitted') {
            return __('active_reports.was_due', ['date' => $row->due->translatedFormat(__('formats.date_short'))]);
        }

        $days = (int) abs($today->diffInDays($row->due));

        return $row->overdue ? trans_choice('active_reports.days_late', $days, ['count' => $days]) : trans_choice('active_reports.due_in_days', $days, ['count' => $days]);
    };
@endphp

<div class="rt-summary">
    <div class="rt-sum is-alert">
        <small>{{ __('active_reports.lane_overdue') }}</small>
        <b class="dk-num">{{ count($lanes['overdue']) }}</b>
        <span>{{ __('active_reports.sum_overdue') }}</span>
    </div>
    <div class="rt-sum">
        <small>{{ __('active_reports.lane_soon') }}</small>
        <b class="dk-num">{{ count($lanes['soon']) }}</b>
        <span dir="auto">{{ $soonNext !== null ? __('active_reports.next_donor', ['donor' => $soonNext->donorName ?? $soonNext->context]) : __('active_reports.nothing_soon') }}</span>
    </div>
    <div class="rt-sum">
        <small>{{ __('active_reports.lane_later') }}</small>
        <b class="dk-num">{{ count($lanes['later']) }}</b>
        <span>{{ __('active_reports.stat_open') }}</span>
    </div>
    <div class="rt-sum">
        <small>{{ __('active_reports.lane_submitted') }}</small>
        <b class="dk-num">{{ count($lanes['submitted']) }}</b>
        <span>{{ __('active_reports.sum_submitted') }}</span>
    </div>
</div>

<div class="rt-toolbar is-bare">
    <div class="rt-chips" role="group" aria-label="{{ __('active_reports.show') }}">
        <button type="button" class="rt-chip" wire:click="setChip('')" aria-pressed="{{ $show === '' ? 'true' : 'false' }}" data-tracker-chip="all">{{ __('active_reports.chip_everyone') }}<span class="rt-chip-n dk-num">{{ $openDonor['open'] }}</span></button>
        <button type="button" class="rt-chip" wire:click="setChip('mine')" aria-pressed="{{ $show === 'mine' ? 'true' : 'false' }}" data-tracker-chip="mine">{{ __('active_reports.chip_mine_to_send') }}<span class="rt-chip-n dk-num">{{ $openDonor['needs'] }}</span></button>
    </div>
</div>

@if ($groups === [])
    <x-ui.empty-state icon="document-check" :heading="__('active_reports.no_matching')" :body="__('common.change_the_search_or_filters_and_try_again')" />
@else
    <div class="rt-stack" x-data="{ page: 1, pages: {{ $donorPages }} }">
        @foreach ($groups as $group)
            @php
                $rowPages = max(1, (int) ceil(count($group['rows']) / $rowPageSize));
                $onDonorPage = intdiv($loop->index, $donorPageSize) + 1;
            @endphp
            <section class="rt-fold" wire:key="tracker-donor-{{ $group['key'] }}" data-tracker-donor="{{ $group['key'] }}" x-data="{ open: false, rp: 1 }" x-show="page === {{ $onDonorPage }}" @if ($onDonorPage > 1) x-cloak @endif>
                <button type="button" class="rt-fold-h" x-on:click="open = ! open" x-bind:aria-expanded="open">
                    <span class="rt-fold-icon">{{ mb_strtoupper(mb_substr($group['name'], 0, 1)) }}</span>
                    <span class="rt-fold-title"><b dir="auto">{{ $group['name'] }}</b><span>{{ $group['next'] !== null ? __('active_reports.next_due', ['date' => $group['next']->translatedFormat(__('formats.date_short'))]) : __('active_reports.nothing_soon') }}</span></span>
                    <span class="rt-fold-counts">
                        @if ($group['overdue'] > 0)<span class="rt-badge is-late dk-num">{{ __('active_reports.n_overdue', ['count' => $group['overdue']]) }}</span>@endif
                        @if ($group['open'] > 0)<span class="rt-badge dk-num">{{ __('active_reports.n_open', ['count' => $group['open']]) }}</span>@endif
                        @if ($group['sent'] > 0)<span class="rt-badge is-quiet dk-num">{{ __('active_reports.n_sent', ['count' => $group['sent']]) }}</span>@endif
                    </span>
                    <x-desk.icon name="chev" class="rt-fold-chev" />
                </button>

                <div class="rt-fold-body" x-show="open" x-collapse x-cloak>
                    @foreach ($group['rows'] as ['row' => $row, 'lane' => $lane])
                        @php $onRowPage = intdiv($loop->index, $rowPageSize) + 1; @endphp
                        <a href="{{ $row->url }}" wire:navigate wire:key="tracker-card-{{ $row->key() }}" @class(['rt-drow', 'is-late' => $row->overdue, 'is-sent' => $lane === 'submitted']) data-tracker-card="{{ $row->key() }}" x-show="rp === {{ $onRowPage }}" @if ($onRowPage > 1) x-cloak @endif>
                            <span class="rt-leaf" aria-hidden="true"><small>{{ $row->due?->translatedFormat(__('formats.month_short')) ?? '—' }}</small><b class="dk-num">{{ $row->due?->day ?? '·' }}</b></span>
                            <span class="rt-drow-main">
                                <b dir="auto">{{ $row->name }}</b>
                                <span dir="auto">{{ $row->context }} · {{ $row->owner ?? __('active_reports.not_assigned') }}</span>
                            </span>
                            <span class="rt-pill" data-state="{{ $lane === 'overdue' ? 'late' : ($lane === 'submitted' ? 'done' : $lane) }}">{{ $laneNames[$lane] }}</span>
                            <span @class(['rt-countdown', 'is-late' => $row->overdue])>{{ $countdown($row, $lane) }}</span>
                        </a>
                    @endforeach

                    @if ($rowPages > 1)
                        <nav class="rt-pager is-mini">
                            <span class="rt-pager-count dk-num" x-text="@js(__('active_reports.page_of', ['page' => '__P__', 'pages' => $rowPages])).replace('__P__', rp)"></span>
                            <span class="rt-pager-pages">
                                <button type="button" class="rt-iconbtn" x-on:click="rp--" x-bind:disabled="rp === 1" aria-label="{{ __('active_reports.previous') }}"><x-desk.icon name="back" class="dk-flip" /></button>
                                <button type="button" class="rt-iconbtn" x-on:click="rp++" x-bind:disabled="rp === {{ $rowPages }}" aria-label="{{ __('active_reports.next') }}"><x-desk.icon name="back" class="rt-fwd" /></button>
                            </span>
                        </nav>
                    @endif
                </div>
            </section>
        @endforeach

        @if ($donorPages > 1)
            <nav class="rt-pager">
                <span class="rt-pager-count dk-num" x-text="@js(__('active_reports.page_of', ['page' => '__P__', 'pages' => $donorPages])).replace('__P__', page)"></span>
                <span class="rt-pager-pages">
                    <button type="button" class="rt-iconbtn" x-on:click="page--; window.scrollTo({ top: 0, behavior: 'smooth' })" x-bind:disabled="page === 1" aria-label="{{ __('active_reports.previous') }}"><x-desk.icon name="back" class="dk-flip" /></button>
                    <button type="button" class="rt-iconbtn" x-on:click="page++; window.scrollTo({ top: 0, behavior: 'smooth' })" x-bind:disabled="page === pages" aria-label="{{ __('active_reports.next') }}"><x-desk.icon name="back" class="rt-fwd" /></button>
                </span>
            </nav>
        @endif
    </div>
@endif
