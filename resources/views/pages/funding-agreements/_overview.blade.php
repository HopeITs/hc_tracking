{{--
    The Overview view of the funding agreements page: the per-currency
    collection ledger, the stage chips and the cash-flow calendar. Included
    by ⚡index.blade.php. It defines its own formatting variables, so it
    reads nothing from the page except $this and $stage.
--}}
@php
    $mutedText = 'text-secondary-medium dark:text-ink-muted';
    $inkText = 'text-secondary-dark dark:text-ink';
    $hairline = 'border-secondary-soft dark:border-line';
    $amount = fn (float $value): string => \Illuminate\Support\Number::format($value, maxPrecision: 0);
    $shortAmount = fn (float $value): string => \Illuminate\Support\Number::abbreviate($value, maxPrecision: 1);
    $calendar = $this->calendar;
    $today = \Carbon\CarbonImmutable::today();
    $stageLabels = [
        'overdue' => __('funding_agreements.stage_overdue'),
        'failed' => __('funding_agreements.stage_failed'),
        'upcoming' => __('funding_agreements.stage_upcoming'),
        'unscheduled' => __('funding_agreements.stage_unscheduled'),
        'in_transit' => __('funding_agreements.stage_in_transit'),
        'received' => __('funding_agreements.stage_received'),
    ];
    $trancheStateLabels = [
        'received' => __('funding_agreements.tranche_state_received'),
        'failed' => __('funding_agreements.tranche_state_failed'),
        'transit' => __('funding_agreements.tranche_state_transit'),
        'late' => __('funding_agreements.tranche_state_overdue'),
        'upcoming' => __('funding_agreements.tranche_state_upcoming'),
    ];
    $stageDot = [
        'overdue' => 'bg-primary dark:bg-status-critical',
        'failed' => 'money-failed',
        'upcoming' => 'money-pending',
        'unscheduled' => 'border border-dashed border-secondary-medium dark:border-ink-faint',
        'in_transit' => 'bg-status-warn',
        'received' => 'bg-funding',
    ];
    $beadClass = [
        'received' => 'bg-funding',
        'upcoming' => 'money-pending border border-status-warn bg-white dark:bg-surface-1',
        'transit' => 'bg-status-warn',
        'late' => 'bg-primary dark:bg-status-critical ring-4 ring-primary/15',
        'failed' => 'border-2 border-primary bg-white dark:bg-surface-1',
    ];
    $tagClass = [
        'received' => 'bg-funding-soft text-funding',
        'upcoming' => 'bg-status-warn-soft text-status-warn',
        'transit' => 'bg-status-warn-soft text-status-warn',
        'late' => 'bg-status-critical-soft text-status-critical',
        'failed' => 'bg-status-critical-soft text-status-critical',
    ];
@endphp

        {{-- The collection ledger: one line per currency, the promise split by where its money is. --}}
        <section aria-labelledby="ledger-heading" class="@container/ledger" data-ledger>
            <h2 id="ledger-heading" class="sr-only">{{ __('funding_agreements.where_the_money_is') }}</h2>

            @forelse ($this->ledger as $line)
                @php
                    // Received is split into what landed, what fees and exchange took and what is
                    // still unrecorded, so the line still adds back up to the promise.
                    $segments = [
                        ['stage' => 'received', 'value' => max(0, $line['receivedGross'] - $line['lost'] - $line['netMissing']), 'display' => $line['received'], 'fill' => 'bg-funding', 'label' => __('funding_agreements.landed_net')],
                        ['stage' => null, 'key' => 'fees', 'value' => $line['lost'], 'display' => $line['lost'], 'fill' => 'bg-funding/35', 'label' => __('funding_agreements.fees_and_exchange')],
                        ['stage' => null, 'key' => 'unrecorded', 'value' => $line['netMissing'], 'display' => $line['netMissing'], 'fill' => 'money-unrecorded', 'label' => __('funding_agreements.received_net_not_recorded')],
                        ['stage' => 'in_transit', 'value' => $line['inTransit'], 'display' => $line['inTransit'], 'fill' => 'bg-status-warn', 'label' => __('funding_agreements.stage_in_transit')],
                        ['stage' => 'upcoming', 'value' => $line['upcoming'], 'display' => $line['upcoming'], 'fill' => 'money-pending', 'label' => __('funding_agreements.stage_upcoming')],
                        ['stage' => 'overdue', 'value' => $line['overdue'], 'display' => $line['overdue'], 'fill' => 'bg-primary dark:bg-status-critical', 'label' => __('funding_agreements.stage_overdue')],
                        ['stage' => 'failed', 'value' => $line['failed'], 'display' => $line['failed'], 'fill' => 'money-failed', 'label' => __('funding_agreements.stage_failed')],
                        ['stage' => 'unscheduled', 'value' => $line['unscheduled'], 'display' => $line['unscheduled'], 'fill' => 'ledger-unscheduled', 'label' => __('funding_agreements.not_scheduled_yet')],
                    ];
                    $scale = max(array_sum(array_column($segments, 'value')), 1);
                    $figures = [
                        ['stage' => 'in_transit', 'value' => $line['inTransit'], 'tone' => 'text-status-warn', 'dot' => 'bg-status-warn', 'label' => __('funding_agreements.stage_in_transit'), 'note' => trans_choice('funding_agreements.count_tranches', $line['inTransitCount'], ['count' => $line['inTransitCount']])],
                        ['stage' => 'upcoming', 'value' => $line['upcoming'], 'tone' => 'text-status-warn', 'dot' => 'money-pending', 'label' => __('funding_agreements.stage_upcoming'), 'note' => $line['nextUpcoming'] !== null ? __('funding_agreements.next_due_on', ['date' => $line['nextUpcoming']->translatedFormat(__('formats.date_short'))]) : trans_choice('funding_agreements.count_tranches', $line['upcomingCount'], ['count' => $line['upcomingCount']])],
                        ['stage' => 'overdue', 'value' => $line['overdue'], 'tone' => 'text-status-critical', 'dot' => 'bg-primary dark:bg-status-critical', 'label' => __('funding_agreements.stage_overdue'), 'note' => trans_choice('funding_agreements.count_tranches', $line['overdueCount'], ['count' => $line['overdueCount']])],
                        ['stage' => 'failed', 'value' => $line['failed'], 'tone' => 'text-status-critical', 'dot' => 'money-failed', 'label' => __('funding_agreements.stage_failed'), 'note' => trans_choice('funding_agreements.count_tranches', $line['failedCount'], ['count' => $line['failedCount']])],
                        ['stage' => 'unscheduled', 'value' => $line['unscheduled'], 'tone' => $inkText, 'dot' => 'ledger-unscheduled', 'label' => __('funding_agreements.not_scheduled_yet'), 'note' => __('funding_agreements.percent_of_committed', ['percent' => $this->percentLabel($line['unscheduled'], $line['committed'])])],
                    ];
                    // Failed money only earns a figure when there is some, or while it is the filter.
                    $figures = array_values(array_filter($figures, fn (array $figure): bool => $figure['stage'] !== 'failed' || $line['failedCount'] > 0 || $stage === 'failed'));
                @endphp
                <div @class(['grid gap-y-5', 'mt-8 border-t pt-8 '.$hairline => ! $loop->first]) wire:key="ledger-{{ $line['currency'] }}" data-ledger-currency="{{ $line['currency'] }}">
                    <div class="grid gap-x-10 gap-y-6 @6xl/ledger:grid-cols-[auto_minmax(0,1fr)] @6xl/ledger:items-end">
                        {{-- The promise and how much of it has landed. --}}
                        <div class="flex flex-wrap items-end gap-x-10 gap-y-4">
                            <div class="min-w-0">
                                <p class="text-xs font-semibold {{ $mutedText }}">{{ __('common.committed') }} · {{ trans_choice('funding_agreements.across_count_agreements', $line['agreements'], ['count' => $line['agreements']]) }}</p>
                                <p class="mt-1.5 text-4xl leading-none font-extrabold tracking-tight tabular-nums @xl/ledger:text-5xl {{ $inkText }}">
                                    <span x-data="countUp({{ (int) round($line['committed']) }})" x-text="new Intl.NumberFormat('en-US').format(display)">{{ $amount($line['committed']) }}</span><span class="ms-2 align-top text-sm font-bold tracking-normal {{ $mutedText }}">{{ $line['currency'] }}</span>
                                </p>
                            </div>
                            <div class="min-w-0">
                                <p class="flex items-center gap-1.5 text-xs font-semibold {{ $mutedText }}"><span class="size-2 rounded-full bg-funding" aria-hidden="true"></span>{{ __('funding_agreements.landed_net') }}</p>
                                <p class="mt-1.5 flex items-baseline gap-2.5 text-4xl leading-none font-extrabold tracking-tight tabular-nums text-funding @xl/ledger:text-5xl" data-ledger-received>
                                    <span x-data="countUp({{ (int) round($line['received']) }})" x-text="new Intl.NumberFormat('en-US').format(display)">{{ $amount($line['received']) }}</span>
                                    <span class="text-lg font-bold tracking-normal">{{ $this->percentLabel($line['received'], $line['committed']) }}%</span>
                                </p>
                            </div>
                        </div>

                        {{-- Where the rest is. Each figure narrows the calendar to the agreements holding it. --}}
                        <ul @class([
                            'grid grid-cols-2 gap-x-6 gap-y-4 @6xl/ledger:justify-items-end',
                            '@2xl/ledger:grid-cols-4' => count($figures) === 4,
                            '@2xl/ledger:grid-cols-3 @4xl/ledger:grid-cols-5' => count($figures) === 5,
                        ])>
                            @foreach ($figures as $figure)
                                @php
                                    $isActive = $stage === $figure['stage'];
                                    $isEmpty = $figure['value'] <= 0;
                                @endphp
                                <li class="min-w-0">
                                    <button
                                        type="button"
                                        wire:click="toggleStage('{{ $figure['stage'] }}')"
                                        aria-pressed="{{ $isActive ? 'true' : 'false' }}"
                                        @disabled($isEmpty && ! $isActive)
                                        @class([
                                            'group -m-2 rounded-lg p-2 text-start transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-primary enabled:hover:bg-board-head disabled:cursor-default dark:enabled:hover:bg-white/5 @6xl/ledger:text-end',
                                            'bg-board-head ring-1 ring-secondary-dark dark:bg-white/5 dark:ring-ink' => $isActive,
                                        ])
                                        data-ledger-figure="{{ $figure['stage'] }}"
                                    >
                                        <span class="flex items-center gap-1.5 text-xs font-semibold {{ $mutedText }} @6xl/ledger:justify-end">
                                            <span class="size-2 shrink-0 rounded-full {{ $figure['dot'] }}" aria-hidden="true"></span>
                                            {{ $figure['label'] }}
                                        </span>
                                        <span @class(['mt-1 block text-2xl leading-none font-extrabold tracking-tight tabular-nums', $figure['tone'] => ! $isEmpty, $mutedText.' opacity-60' => $isEmpty])>{{ $amount($figure['value']) }}</span>
                                        <span class="mt-1 block text-[11px] tabular-nums {{ $mutedText }} group-enabled:group-hover:underline group-aria-pressed:underline">{{ $figure['note'] }}</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    {{-- One collection line: the whole promise, split by where its money is. --}}
                    <div>
                        <div class="flex h-3 w-full gap-[3px]" role="group" aria-label="{{ __('funding_agreements.collection_line_label', ['currency' => $line['currency']]) }}">
                            @foreach ($segments as $segment)
                                @continue($segment['value'] <= 0)
                                @php
                                    $segmentText = $segment['label'].' · '.$amount($segment['display']).' '.$line['currency'];
                                    $isActive = $segment['stage'] !== null && $stage === $segment['stage'];
                                @endphp
                                @if ($segment['stage'] === null)
                                    {{-- Part of what was received, not a place money can be stuck, so not a filter. --}}
                                    <span
                                        role="img"
                                        aria-label="{{ $segmentText }}"
                                        title="{{ $segmentText }}"
                                        class="ledger-segment h-full min-w-1.5 first:rounded-s-full last:rounded-e-full {{ $segment['fill'] }}"
                                        style="flex: {{ round($segment['value'] / $scale * 1000) }} 1 0%"
                                        data-segment="{{ $segment['key'] }}"
                                    ></span>
                                @else
                                    <button
                                        type="button"
                                        wire:click="toggleStage('{{ $segment['stage'] }}')"
                                        aria-pressed="{{ $isActive ? 'true' : 'false' }}"
                                        aria-label="{{ __('funding_agreements.show_agreements_stage', ['stage' => $segment['label'], 'amount' => $amount($segment['display']), 'currency' => $line['currency']]) }}"
                                        title="{{ $segmentText }}"
                                        @class([
                                            'ledger-segment h-full min-w-1.5 cursor-pointer first:rounded-s-full last:rounded-e-full transition-[filter,transform] duration-150 hover:brightness-110 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                                            $segment['fill'],
                                            'outline-2 outline-offset-2 outline-secondary-dark dark:outline-ink' => $isActive,
                                        ])
                                        style="flex: {{ round($segment['value'] / $scale * 1000) }} 1 0%"
                                        data-segment="{{ $segment['stage'] }}"
                                    ></button>
                                @endif
                            @endforeach
                        </div>
                        <p class="mt-2.5 flex flex-wrap gap-x-5 gap-y-1 text-xs tabular-nums {{ $mutedText }}" data-ledger-notes>
                            <span>{{ __('funding_agreements.received_gross_from_donors') }} <span class="font-bold {{ $inkText }}">{{ $amount($line['receivedGross']) }} {{ $line['currency'] }}</span></span>
                            @if ($line['lost'] > 0)
                                <span>{{ __('funding_agreements.fees_and_exchange') }} −{{ $amount($line['lost']) }}</span>
                            @endif
                            @if ($line['gained'] > 0)
                                <span class="text-funding">{{ __('funding_agreements.exchange_gain') }} +{{ $amount($line['gained']) }}</span>
                            @endif
                            @if ($line['netMissingCount'] > 0)
                                <span class="font-semibold text-status-warn" data-ledger-net-missing>{{ trans_choice('funding_agreements.count_received_without_net', $line['netMissingCount'], ['count' => $line['netMissingCount'], 'amount' => $amount($line['netMissing']), 'currency' => $line['currency']]) }}</span>
                            @endif
                            @if ($line['overscheduled'] > 0)
                                <span>{{ __('funding_agreements.scheduled_beyond_the_budget', ['amount' => $amount($line['overscheduled']), 'currency' => $line['currency']]) }}</span>
                            @endif
                            @if ($line['landedElsewhere'] > 0)
                                <span>{{ __('funding_agreements.landed_in_another_currency', ['amount' => $amount($line['landedElsewhere']), 'currency' => $line['currency']]) }}</span>
                            @endif
                            @if ($line['otherCurrencyTranches'] > 0)
                                <span>{{ trans_choice('funding_agreements.count_tranches_sent_in_another_currency', $line['otherCurrencyTranches'], ['count' => $line['otherCurrencyTranches']]) }}</span>
                            @endif
                        </p>
                    </div>
                </div>
            @empty
                <p class="text-sm {{ $mutedText }}">{{ __('funding_agreements.no_money_committed_under_these_filters') }}</p>
            @endforelse
        </section>

        {{-- The cash-flow calendar: agreements as lanes, tranches on their dates, today as one line. --}}
        <section
            aria-labelledby="calendar-heading"
            class="@container/cal"
            x-data="{
                zoom: $persist('month').as('agreements-calendar-zoom'),
                activePeriod: null,
                open: null,
                focusTranche: null,
                viewWidth: 0,
                init() {
                    const scroller = this.$refs.scroller;
                    new ResizeObserver(() => { this.viewWidth = scroller.clientWidth; }).observe(scroller);
                    this.$nextTick(() => this.goToToday('instant'));
                },
                goToToday(behavior = 'smooth') {
                    const scroller = this.$refs.scroller;
                    const today = this.$refs.today;
                    const lane = this.$refs.laneHead;
                    if (! today || ! lane || scroller.scrollWidth <= scroller.clientWidth) { return; }
                    const rtl = getComputedStyle(scroller).direction === 'rtl';
                    const box = scroller.getBoundingClientRect();
                    const mark = today.getBoundingClientRect();
                    const fromStart = rtl ? box.right - mark.right : mark.left - box.left;
                    const wanted = lane.offsetWidth + (scroller.clientWidth - lane.offsetWidth) / 3;
                    const shift = fromStart - wanted;
                    scroller.scrollBy({ left: rtl ? -shift : shift, behavior });
                },
                setZoom(level) {
                    this.zoom = level;
                    this.activePeriod = null;
                    this.$nextTick(() => this.goToToday());
                },
                toggleLane(id, trancheId = null) {
                    this.focusTranche = trancheId;
                    this.open = this.open === id && trancheId === null ? null : id;
                },
                togglePeriod(key) {
                    this.activePeriod = this.activePeriod === key ? null : key;
                },
                dims(month, quarter) {
                    return this.activePeriod !== null && this.activePeriod !== month && this.activePeriod !== quarter;
                },
            }"
            :style="{ '--month-w': zoom === 'quarter' ? '2.625rem' : '7.25rem', '--view-w': viewWidth + 'px' }"
            style="--month-count: {{ $calendar['count'] }}; --lane-w: 23rem; --track-w: calc(var(--month-w) * var(--month-count))"
            data-calendar
        >
            <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-3">
                <div class="flex min-w-0 flex-wrap items-baseline gap-x-3 gap-y-1">
                    <h2 id="calendar-heading" class="text-lg font-bold {{ $inkText }}">{{ __('funding_agreements.cash_flow_calendar') }}</h2>
                    <p class="text-xs {{ $mutedText }}">{{ trans_choice('funding_agreements.count_matching_agreement', $this->agreements->total(), ['count' => $this->agreements->total()]) }} · {{ __('funding_agreements.most_urgent_first') }}</p>
                </div>

                <div class="hidden items-center gap-2 @3xl/cal:flex">
                    <button type="button" x-on:click="goToToday()" class="inline-flex min-h-9 items-center gap-1.5 rounded-lg border px-3 text-xs font-semibold transition-colors hover:bg-board-head focus-visible:outline-2 focus-visible:outline-primary dark:hover:bg-white/5 {{ $hairline }} {{ $inkText }}" data-jump-today>
                        <span class="size-2 rounded-full bg-primary dark:bg-ink" aria-hidden="true"></span>
                        {{ __('common.today') }}
                    </button>
                    <div class="inline-flex rounded-lg bg-board-head p-1 dark:bg-white/5" role="group" aria-label="{{ __('funding_agreements.calendar_zoom') }}">
                        <button type="button" x-on:click="setZoom('month')" :aria-pressed="(zoom === 'month').toString()" class="min-h-7 rounded-md px-3 text-xs font-bold transition-colors {{ $mutedText }} aria-pressed:bg-white aria-pressed:text-secondary-dark aria-pressed:shadow-sm dark:aria-pressed:bg-surface-2 dark:aria-pressed:text-ink">{{ __('funding_agreements.zoom_months') }}</button>
                        <button type="button" x-on:click="setZoom('quarter')" :aria-pressed="(zoom === 'quarter').toString()" class="min-h-7 rounded-md px-3 text-xs font-bold transition-colors {{ $mutedText }} aria-pressed:bg-white aria-pressed:text-secondary-dark aria-pressed:shadow-sm dark:aria-pressed:bg-surface-2 dark:aria-pressed:text-ink">{{ __('funding_agreements.zoom_quarters') }}</button>
                    </div>
                </div>
            </div>

            {{-- Stage chips: the same questions as the ledger figures, answered in agreements. --}}
            <nav aria-label="{{ __('funding_agreements.filter_by_where_the_money_is') }}" class="mt-4 flex flex-wrap items-center gap-2" data-stage-chips>
                @foreach ($this::STAGES as $stageKey)
                    @php
                        $stageCount = $this->stageCounts[$stageKey] ?? 0;
                        $isActive = $stage === $stageKey;
                    @endphp
                    <button
                        type="button"
                        wire:click="toggleStage('{{ $stageKey }}')"
                        aria-pressed="{{ $isActive ? 'true' : 'false' }}"
                        @disabled($stageCount === 0 && ! $isActive)
                        @class([
                            'inline-flex min-h-9 items-center gap-2 rounded-full border ps-3 pe-3.5 text-xs font-semibold transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-default disabled:opacity-45',
                            'border-secondary-dark bg-secondary-dark text-white dark:border-ink dark:bg-ink dark:text-surface-0' => $isActive,
                            $hairline.' hover:border-secondary-light hover:bg-board-head dark:hover:bg-white/5 '.$inkText => ! $isActive,
                        ])
                        data-stage="{{ $stageKey }}"
                        wire:key="stage-chip-{{ $stageKey }}"
                    >
                        <span class="size-2.5 shrink-0 rounded-full {{ $stageDot[$stageKey] }}" aria-hidden="true"></span>
                        {{ $stageLabels[$stageKey] }}
                        <span @class(['tabular-nums', 'text-white/70 dark:text-surface-0/70' => $isActive, $mutedText => ! $isActive])>{{ $stageCount }}</span>
                        @if ($isActive)
                            <flux:icon.x-mark variant="micro" class="size-3.5" />
                        @endif
                    </button>
                @endforeach
            </nav>

            @if ($this->agreements->isEmpty())
                <x-ui.empty-state icon="banknotes" :heading="__('funding_agreements.no_matching_agreements')" :body="__('common.change_the_search_or_filters_and_try_again')" />
            @else
                <div
                    x-ref="scroller"
                    class="cal-scroller relative mt-5 border-y {{ $hairline }} @3xl/cal:max-h-[max(32rem,calc(100dvh-8rem))] @3xl/cal:overflow-auto @3xl/cal:overscroll-x-contain"
                    x-on:keydown.escape="open = null; activePeriod = null"
                >
                    <div class="cal-grid relative @3xl/cal:w-max @3xl/cal:min-w-full">
                        {{-- Period grid lines, the current and selected period's wash and the today line, behind every lane. --}}
                        <div class="pointer-events-none absolute inset-y-0 hidden @3xl/cal:block" style="inset-inline-start: var(--lane-w); width: var(--track-w)" aria-hidden="true">
                            @foreach ($calendar['months'] as $index => $month)
                                <span
                                    @class([
                                        'absolute inset-y-0 transition-colors duration-200',
                                        'bg-board-head/70 dark:bg-white/[0.03]' => $month['current'],
                                    ])
                                    :class="activePeriod === @js($month['key']) && 'bg-funding-soft/80! dark:bg-funding-soft/60!'"
                                    x-show="zoom === 'month'"
                                    style="inset-inline-start: calc(var(--month-w) * {{ $index }}); width: var(--month-w)"
                                ></span>
                                <span
                                    @class([
                                        'absolute inset-y-0 border-s',
                                        'border-secondary-light' => $month['year'] !== null,
                                        'border-secondary-soft/70 dark:border-line-strong' => $month['year'] === null && $month['quarter'],
                                        'border-secondary-soft/40 dark:border-line' => ! $month['quarter'],
                                    ])
                                    @if (! $month['quarter']) x-show="zoom === 'month'" @endif
                                    style="inset-inline-start: calc(var(--month-w) * {{ $index }})"
                                ></span>
                            @endforeach
                            @foreach ($calendar['quarters'] as $quarter)
                                <span
                                    @class([
                                        'absolute inset-y-0 transition-colors duration-200',
                                        'bg-board-head/70 dark:bg-white/[0.03]' => $quarter['current'],
                                    ])
                                    :class="activePeriod === @js($quarter['key']) && 'bg-funding-soft/80! dark:bg-funding-soft/60!'"
                                    x-show="zoom === 'quarter'"
                                    style="inset-inline-start: calc(var(--month-w) * {{ $quarter['index'] }}); width: calc(var(--month-w) * 3)"
                                ></span>
                            @endforeach
                            <span x-ref="today" class="cal-today absolute inset-y-0 z-[1] w-0.5 -translate-x-1/2 bg-primary dark:bg-ink rtl:translate-x-1/2" style="inset-inline-start: calc(var(--month-w) * {{ $calendar['today'] }})"></span>
                        </div>

                        @php
                            $periodRows = [
                                'month' => collect($calendar['months'])->map(fn (array $month, int $index): array => [
                                    'key' => $month['key'],
                                    'index' => $index,
                                    'span' => 1,
                                    'name' => $month['date']->translatedFormat(__('formats.month_year')),
                                    'label' => $month['label'],
                                    'year' => $month['year'],
                                    'current' => $month['current'],
                                ])->all(),
                                'quarter' => collect($calendar['quarters'])->map(fn (array $quarter): array => [
                                    'key' => $quarter['key'],
                                    'index' => $quarter['index'],
                                    'span' => 3,
                                    'name' => $quarter['label'].' '.substr($quarter['key'], 0, 4),
                                    'label' => $quarter['label'],
                                    'year' => $quarter['year'],
                                    'current' => $quarter['current'],
                                ])->all(),
                            ];
                        @endphp

                        {{-- Sticky head: period labels and what is expected to land in each period. --}}
                        <div class="sticky top-0 z-20 hidden bg-white/95 backdrop-blur-sm @3xl/cal:grid dark:bg-surface-1/95" style="grid-template-columns: var(--lane-w) var(--track-w)">
                            <div x-ref="laneHead" class="sticky start-0 z-10 flex flex-col justify-end gap-1 border-e bg-white px-4 pt-3 pb-2 dark:bg-surface-1 {{ $hairline }}">
                                <p class="text-xs font-bold {{ $inkText }}" x-text="zoom === 'quarter' ? @js(__('funding_agreements.expected_each_quarter')) : @js(__('funding_agreements.expected_each_month'))">{{ __('funding_agreements.expected_each_month') }}</p>
                                <p class="text-[11px] leading-snug {{ $mutedText }}" x-text="zoom === 'quarter' ? @js(__('funding_agreements.select_a_quarter_to_highlight_its_tranches')) : @js(__('funding_agreements.select_a_month_to_highlight_its_tranches'))">{{ __('funding_agreements.select_a_month_to_highlight_its_tranches') }}</p>
                            </div>
                            <div class="relative border-b {{ $hairline }}">
                                <div class="relative h-10">
                                    @foreach ($periodRows as $mode => $periods)
                                        @foreach ($periods as $period)
                                            <span
                                                @class([
                                                    'absolute bottom-1.5 ps-2 text-[11px] whitespace-nowrap tabular-nums',
                                                    'font-bold '.$inkText => $period['year'] !== null || $period['current'],
                                                    $mutedText => $period['year'] === null && ! $period['current'],
                                                ])
                                                x-show="zoom === @js($mode)"
                                                style="inset-inline-start: calc(var(--month-w) * {{ $period['index'] }})"
                                                wire:key="label-{{ $period['key'] }}"
                                            >{{ $period['label'] }}@if ($period['year'] !== null) <span class="{{ $mutedText }} font-semibold">{{ $period['year'] }}</span>@endif</span>
                                        @endforeach
                                    @endforeach
                                    <span class="absolute top-0.5 z-[2] -translate-x-1/2 rounded-full bg-primary dark:bg-ink dark:text-surface-0 px-1.5 py-px text-[10px] leading-4 font-bold text-white rtl:translate-x-1/2" style="inset-inline-start: calc(var(--month-w) * {{ $calendar['today'] }})">{{ __('common.today') }}</span>
                                </div>

                                @foreach ($calendar['inflow'] as $inflowCurrency => $inflow)
                                    @foreach ($periodRows as $mode => $periods)
                                        @php
                                            $moneyByPeriod = $mode === 'quarter' ? $inflow['quarters'] : $inflow['months'];
                                            $periodMax = $mode === 'quarter' ? $inflow['quarterMax'] : $inflow['max'];
                                            $barHeight = fn (float $value): float => $periodMax > 0 ? round($value / $periodMax * 100, 2) : 0;
                                        @endphp
                                        <div class="relative flex h-14" style="width: var(--track-w)" x-show="zoom === @js($mode)" wire:key="inflow-{{ $inflowCurrency }}-{{ $mode }}" data-inflow="{{ $inflowCurrency }}" data-inflow-mode="{{ $mode }}">
                                            @foreach ($periods as $period)
                                                @php
                                                    $periodMoney = $moneyByPeriod[$period['key']] ?? null;
                                                @endphp
                                                <div class="relative h-full shrink-0" style="width: calc(var(--month-w) * {{ $period['span'] }})">
                                                    @if ($periodMoney !== null)
                                                        <button
                                                            type="button"
                                                            x-on:click="togglePeriod(@js($period['key']))"
                                                            :aria-pressed="(activePeriod === @js($period['key'])).toString()"
                                                            aria-label="{{ __('funding_agreements.month_inflow_label', [
                                                                'month' => $period['name'],
                                                                'total' => $amount($periodMoney['total']),
                                                                'currency' => $inflowCurrency,
                                                                'received' => $amount($periodMoney['received']),
                                                                'pending' => $amount($periodMoney['pending']),
                                                                'late' => $amount($periodMoney['late']),
                                                                'failed' => $amount($periodMoney['failed']),
                                                            ]) }}"
                                                            class="absolute inset-x-1 inset-y-1 flex items-end gap-1.5 rounded-md px-1 pb-1 text-start transition-colors hover:bg-board-head focus-visible:outline-2 focus-visible:outline-primary aria-pressed:bg-funding-soft dark:hover:bg-white/5"
                                                            data-period="{{ $period['key'] }}"
                                                        >
                                                            <span class="flex h-full w-1.5 shrink-0 flex-col-reverse overflow-hidden rounded-full" aria-hidden="true">
                                                                <span class="cal-inflow-bar shrink-0 bg-funding" style="height: {{ $barHeight($periodMoney['received']) }}%"></span>
                                                                <span class="cal-inflow-bar money-pending shrink-0" style="height: {{ $barHeight($periodMoney['pending']) }}%"></span>
                                                                <span class="cal-inflow-bar shrink-0 bg-primary dark:bg-status-critical" style="height: {{ $barHeight($periodMoney['late']) }}%"></span>
                                                                <span class="cal-inflow-bar money-failed shrink-0" style="height: {{ $barHeight($periodMoney['failed']) }}%"></span>
                                                            </span>
                                                            @php
                                                                $periodInTrouble = $periodMoney['late'] > 0 || $periodMoney['failed'] > 0;
                                                            @endphp
                                                            <span class="min-w-0 leading-tight">
                                                                <span @class([
                                                                    'block truncate text-sm font-extrabold tabular-nums',
                                                                    'text-status-critical' => $periodInTrouble,
                                                                    'text-funding' => ! $periodInTrouble && $periodMoney['pending'] <= 0,
                                                                    $inkText => ! $periodInTrouble && $periodMoney['pending'] > 0,
                                                                ])>{{ $shortAmount($periodMoney['total']) }}</span>
                                                                <span class="block text-[10px] font-semibold {{ $mutedText }}">{{ $inflowCurrency }}</span>
                                                            </span>
                                                        </button>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    @endforeach
                                @endforeach
                            </div>
                        </div>

                        {{-- Lanes --}}
                        <ol class="relative" aria-label="{{ __('funding_agreements.cash_flow_calendar') }}">
                            @foreach ($this->agreements as $agreement)
                                @php
                                    $money = $this->agreementMoney($agreement);
                                    $budget = (float) $agreement->budget;
                                    $agreementName = $agreement->donor->name.' · '.$agreement->proposal->name;
                                    $projects = $agreement->proposal->projects;
                                    $placed = $agreement->transfers->filter(fn ($transfer) => $transfer->scheduled_date !== null)->values();
                                    $offsets = $placed->map(fn ($transfer) => $this->monthOffset($calendar['start'], $transfer->scheduled_date));
                                    $lastReceived = $placed->filter(fn ($transfer) => $transfer->ar_status === \App\Enums\ArStatus::Received)->keys()->last();
                                    $hasOverdue = $money['overdueSince'] !== null;
                                @endphp
                                <li
                                    wire:key="agreement-{{ $agreement->id }}"
                                    class="cal-lane group/lane relative border-b last:border-b-0 {{ $hairline }}"
                                    :class="open === {{ $agreement->id }} && 'is-open'"
                                    data-agreement-row="{{ $agreement->id }}"
                                    style="--lane-order: {{ min($loop->index, 10) }}"
                                >
                                    <div class="grid @3xl/cal:grid-cols-[var(--lane-w)_var(--track-w)]">
                                        {{-- Identity and money, pinned while the months scroll. --}}
                                        <div class="relative z-10 flex gap-3.5 bg-white py-4 transition-colors duration-150 group-hover/lane:bg-board-head group-[.is-open]/lane:bg-board-head @3xl/cal:sticky @3xl/cal:start-0 @3xl/cal:border-e @3xl/cal:px-4 dark:bg-surface-1 {{ $hairline }}">
                                            <x-ui.progress-ring :value="$money['share']" size="md" tone="funding" :label="__('funding_agreements.percent_received', ['percent' => $money['percentLabel']])" class="mt-0.5" />
                                            <div class="min-w-0 flex-1">
                                                <div class="flex items-start justify-between gap-2">
                                                    <p class="min-w-0 text-sm leading-snug font-bold {{ $inkText }}">
                                                        <a href="{{ route('donors.show', $agreement->donor) }}" wire:navigate class="underline-offset-4 hover:underline">{{ $agreement->donor->name }}</a>
                                                        <span class="{{ $mutedText }}" aria-hidden="true">·</span>
                                                        <a href="{{ route('proposals.show', $agreement->proposal) }}" wire:navigate class="underline-offset-4 hover:underline">{{ $agreement->proposal->name }}</a>
                                                    </p>
                                                    {{-- No delete: transfers_tracking cascades from this row, so
                                                         deleting an agreement would erase the money received. --}}
                                                    <div class="-me-1.5 -mt-1.5 flex shrink-0 items-center">
                                                        <x-ui.button tone="ghost" size="sm" icon="eye" class="size-9! shrink-0 touch-manipulation" :href="route('funding-agreements.show', $agreement)" wire:navigate :aria-label="__('common.view_name', ['name' => $agreementName])" />
                                                        @can('funding-agreements.update')
                                                        <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9! shrink-0 touch-manipulation" wire:click="openEditModal({{ $agreement->id }})" :aria-label="__('common.edit_name', ['name' => $agreementName])" />
                                                        @endcan
                                                    </div>
                                                </div>

                                                <p class="mt-1 text-lg leading-none font-extrabold tracking-tight tabular-nums {{ $inkText }}">
                                                    {{ $amount($budget) }} <span class="text-[11px] font-bold tracking-normal {{ $mutedText }}">{{ $agreement->currency->value }}</span>
                                                    <span class="ms-1 text-xs font-bold tracking-normal text-funding">{{ __('funding_agreements.percent_received', ['percent' => $money['percentLabel']]) }}</span>
                                                </p>

                                                @if ($hasOverdue)
                                                    <p class="mt-1.5 flex items-center gap-1.5 text-xs font-bold text-status-critical tabular-nums" data-overdue>
                                                        <span class="size-1.5 rounded-full bg-primary dark:bg-status-critical" aria-hidden="true"></span>
                                                        {{ __('funding_agreements.amount_overdue_since', ['amount' => $shortAmount($money['overdueAmount']), 'date' => $money['overdueSince']->translatedFormat(__('formats.date_short'))]) }}
                                                        · {{ trans_choice('funding_agreements.count_days_late', (int) $money['overdueSince']->diffInDays(\Carbon\CarbonImmutable::today()), ['count' => (int) $money['overdueSince']->diffInDays(\Carbon\CarbonImmutable::today())]) }}
                                                    </p>
                                                @elseif ($money['nextDue'] !== null)
                                                    <p class="mt-1.5 text-xs tabular-nums {{ $mutedText }}">{{ __('funding_agreements.next_due_on', ['date' => $money['nextDue']->translatedFormat(__('formats.date'))]) }}</p>
                                                @elseif ($money['unscheduled'] > 0)
                                                    <p class="mt-1.5 text-xs tabular-nums {{ $mutedText }}">{{ __('funding_agreements.amount_not_scheduled', ['amount' => $shortAmount($money['unscheduled'])]) }}</p>
                                                @endif

                                                @if ($money['failedAmount'] > 0)
                                                    <p class="mt-1 flex items-center gap-1.5 text-xs font-bold text-status-critical tabular-nums" data-failed>
                                                        <span class="money-failed size-1.5 rounded-full" aria-hidden="true"></span>
                                                        {{ __('funding_agreements.amount_failed', ['amount' => $shortAmount($money['failedAmount'])]) }}
                                                    </p>
                                                @endif

                                                @if ($money['missingNet'] > 0)
                                                    <p class="mt-1 text-xs font-semibold text-status-warn" data-net-missing>{{ trans_choice('funding_agreements.count_tranches_without_net', $money['missingNet'], ['count' => $money['missingNet']]) }}</p>
                                                @endif

                                                @if ($agreement->overdue_reports_count > 0)
                                                    <p class="mt-1 text-xs font-bold text-status-critical" data-report-overdue>{{ trans_choice('funding_agreements.count_donor_reports_overdue', $agreement->overdue_reports_count, ['count' => $agreement->overdue_reports_count]) }}</p>
                                                @elseif ($agreement->next_report_due !== null)
                                                    <p class="mt-1 text-xs tabular-nums {{ $mutedText }}" data-report-due>{{ __('funding_agreements.donor_report_due_on', ['date' => \Carbon\CarbonImmutable::parse($agreement->next_report_due)->translatedFormat(__('formats.date'))]) }}</p>
                                                @endif

                                                <button
                                                    type="button"
                                                    x-on:click="toggleLane({{ $agreement->id }})"
                                                    :aria-expanded="(open === {{ $agreement->id }}).toString()"
                                                    aria-controls="agreement-ledger-{{ $agreement->id }}"
                                                    class="mt-2 inline-flex min-h-8 items-center gap-1.5 rounded-md text-xs font-semibold underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-primary {{ $inkText }}"
                                                    data-lane-toggle
                                                >
                                                    <flux:icon.chevron-down variant="micro" class="size-4 transition-transform duration-200" ::class="open === {{ $agreement->id }} && 'rotate-180'" />
                                                    {{ trans_choice('common.count_tranche', $agreement->transfers->count(), ['count' => $agreement->transfers->count()]) }}
                                                    @if ($projects->isNotEmpty())
                                                        <span class="{{ $mutedText }}" aria-hidden="true">·</span>
                                                        <span class="truncate">{{ trans_choice('funding_agreements.funds_count_projects', $projects->count(), ['count' => $projects->count()]) }}</span>
                                                    @endif
                                                </button>
                                            </div>
                                        </div>

                                        {{-- The lane's track: tranches placed on their dates. --}}
                                        <div class="relative hidden min-h-full @3xl/cal:block" style="width: var(--track-w)">
                                            @if ($placed->isEmpty())
                                                <p class="sticky start-[calc(var(--lane-w)+1rem)] flex h-full w-max items-center gap-2 px-4 text-xs {{ $mutedText }}">
                                                    <span class="size-3 rounded-full border border-dashed border-secondary-medium dark:border-ink-faint" aria-hidden="true"></span>
                                                    {{ __('funding_agreements.no_tranches_scheduled_yet') }}
                                                </p>
                                            @else
                                                <ol class="absolute inset-0" aria-label="{{ __('funding_agreements.tranches_of_name', ['name' => $agreementName]) }}">
                                                    {{-- The agreement's life: first to last tranche, teal as far as money has landed. --}}
                                                    <span class="absolute top-1/2 h-px -translate-y-1/2 bg-secondary-light/70" style="inset-inline-start: calc(var(--month-w) * {{ $offsets->min() }}); width: calc(var(--month-w) * {{ max(0, $offsets->max() - $offsets->min()) }})" aria-hidden="true"></span>
                                                    @if ($lastReceived !== null)
                                                        <span class="cal-thread absolute top-1/2 h-[3px] -translate-y-1/2 rounded-full bg-funding" style="inset-inline-start: calc(var(--month-w) * {{ $offsets->min() }}); width: calc(var(--month-w) * {{ max(0, $offsets[$lastReceived] - $offsets->min()) }})" aria-hidden="true"></span>
                                                    @endif

                                                    @foreach ($placed as $position => $transfer)
                                                        @php
                                                            $state = $this->trancheState($transfer);
                                                            $offset = $offsets[$position];
                                                            $crowded = $position > 0 && ($offset - $offsets[$position - 1]) < 0.85;
                                                            // At quarter zoom a month is under 3rem wide: only label a tranche with two clear months after it.
                                                            $roomy = ! isset($offsets[$position + 1]) || ($offsets[$position + 1] - $offset) >= 2;
                                                            $size = round(0.625 + 0.625 * sqrt($budget > 0 ? min(1, (float) $transfer->payment_amount / $budget) : 0.2), 3);
                                                            $monthKey = $transfer->scheduled_date->format('Y-m');
                                                            $stateLabel = $trancheStateLabels[$state];
                                                            $trancheText = __('funding_agreements.tranche_summary', [
                                                                'amount' => $amount((float) $transfer->payment_amount),
                                                                'currency' => $transfer->amount_transfered_currency->value,
                                                                'date' => $transfer->scheduled_date->translatedFormat(__('formats.date')),
                                                                'state' => $stateLabel,
                                                            ]);
                                                        @endphp
                                                        @if ($state === 'late')
                                                            {{-- How late: a red tail from the missed date to today. --}}
                                                            <span class="cal-late-tail absolute top-1/2 h-0 -translate-y-1/2 border-t-2 border-dashed border-status-critical" style="inset-inline-start: calc(var(--month-w) * {{ $offset }}); width: calc(var(--month-w) * {{ max(0, $calendar['today'] - $offset) }})" aria-hidden="true"></span>
                                                        @endif
                                                        <li
                                                            class="cal-pill group/pill absolute top-1/2 z-[2] transition-opacity duration-200 hover:z-30 focus-within:z-30"
                                                            :class="dims(@js($monthKey), @js($this->quarterKey($transfer->scheduled_date))) && 'opacity-20'"
                                                            style="inset-inline-start: calc(var(--month-w) * {{ $offset }}); --pill-order: {{ min((int) floor($offset), 24) }}"
                                                            wire:key="pill-{{ $transfer->id }}"
                                                        >
                                                            <button
                                                                type="button"
                                                                x-on:click="toggleLane({{ $agreement->id }}, {{ $transfer->id }})"
                                                                aria-label="{{ $trancheText }}"
                                                                aria-controls="agreement-ledger-{{ $agreement->id }}"
                                                                class="relative flex -translate-x-1/2 -translate-y-1/2 items-center rounded-full focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary rtl:translate-x-1/2"
                                                                data-bead="{{ $state }}"
                                                            >
                                                                <span @class(['tranche-bead block shrink-0 rounded-full transition-transform duration-150 ease-out group-hover/pill:scale-125', $beadClass[$state]]) style="width: {{ $size }}rem; height: {{ $size }}rem" aria-hidden="true"></span>
                                                                <span
                                                                    @class([
                                                                        'absolute start-full ms-1.5 rounded-full px-1.5 py-px text-[11px] leading-4 font-bold whitespace-nowrap tabular-nums',
                                                                        $tagClass[$state],
                                                                        'bottom-full mb-0.5 -ms-1' => $crowded,
                                                                    ])
                                                                    x-show="zoom === 'month' || @js($roomy)"
                                                                    aria-hidden="true"
                                                                >{{ $shortAmount((float) $transfer->payment_amount) }}</span>
                                                            </button>
                                                            {{-- Tooltip: the tranche in words. --}}
                                                            <span class="pointer-events-none invisible absolute top-full start-0 z-30 mt-3 w-max max-w-60 -translate-x-1/2 rounded-lg bg-secondary-dark px-2.5 py-2 text-start text-[11px] leading-snug text-white opacity-0 shadow-[0_8px_24px_-8px_rgb(0_0_0/0.4)] transition-opacity duration-150 group-hover/pill:visible group-hover/pill:opacity-100 group-focus-within/pill:visible group-focus-within/pill:opacity-100 rtl:translate-x-1/2 dark:bg-surface-3 dark:shadow-raised dark:text-ink" aria-hidden="true">
                                                                <span class="block text-xs font-bold tabular-nums">{{ $amount((float) $transfer->payment_amount) }} {{ $transfer->amount_transfered_currency->value }}</span>
                                                                <span class="block tabular-nums opacity-80">{{ $transfer->scheduled_date->translatedFormat(__('formats.date')) }} · {{ $stateLabel }}</span>
                                                                @if ($state === 'received' && $transfer->net_amount_received !== null)
                                                                    <span class="block tabular-nums opacity-80">{{ __('funding_agreements.net_amount_landed', ['amount' => $amount((float) $transfer->net_amount_received), 'currency' => $transfer->amount_received_currency->value]) }}</span>
                                                                @elseif ($transfer->isMissingNetAmount())
                                                                    <span class="block opacity-80">{{ __('funding_agreements.net_not_recorded') }}</span>
                                                                @endif
                                                                <span class="block opacity-80">{{ $transfer->account?->account_name ?? __('funding_agreements.no_account_assigned') }}</span>
                                                            </span>
                                                        </li>
                                                    @endforeach
                                                </ol>
                                            @endif
                                        </div>
                                    </div>

                                    {{-- Phones: no shared month grid, so the tranches read as a short list. --}}
                                    @if ($placed->isNotEmpty())
                                        <ol class="-mt-1 flex flex-wrap gap-1.5 pb-4 ps-[3.875rem] @3xl/cal:hidden" aria-label="{{ __('funding_agreements.tranches_of_name', ['name' => $agreementName]) }}">
                                            @foreach ($placed as $transfer)
                                                @php
                                                    $state = $this->trancheState($transfer);
                                                @endphp
                                                <li class="inline-flex items-center gap-1.5 rounded-full py-0.5 ps-1.5 pe-2 text-[11px] font-bold tabular-nums {{ $tagClass[$state] }}" wire:key="chip-{{ $transfer->id }}">
                                                    <span class="size-2 shrink-0 rounded-full {{ $beadClass[$state] }}" aria-hidden="true"></span>
                                                    {{ $shortAmount((float) $transfer->payment_amount) }}
                                                    <span class="font-semibold opacity-80">{{ $transfer->scheduled_date->translatedFormat(__('formats.date_short')) }}</span>
                                                    <span class="sr-only">{{ $trancheStateLabels[$state] }}</span>
                                                </li>
                                            @endforeach
                                        </ol>
                                    @endif

                                    {{-- The agreement opened in place: its connections and every tranche. --}}
                                    <div
                                        id="agreement-ledger-{{ $agreement->id }}"
                                        class="cal-ledger grid"
                                        :class="open === {{ $agreement->id }} ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'"
                                        :inert="open !== {{ $agreement->id }}"
                                        data-agreement-ledger
                                    >
                                        <div class="min-h-0 overflow-clip">
                                            <div class="sticky start-0 z-[3] grid gap-x-10 gap-y-6 border-t bg-board-head px-4 py-5 @3xl/cal:w-[var(--view-w)] @4xl/cal:grid-cols-[minmax(14rem,0.8fr)_minmax(0,2fr)] dark:bg-white/[0.03] {{ $hairline }}">
                                                <dl class="grid content-start gap-3 text-xs">
                                                    <div>
                                                        <dt class="font-semibold {{ $mutedText }}">{{ __('common.proposal') }}</dt>
                                                        <dd class="mt-1 flex flex-wrap items-center gap-2">
                                                            <a href="{{ route('proposals.show', $agreement->proposal) }}" wire:navigate class="font-bold underline-offset-4 hover:underline {{ $inkText }}">{{ $agreement->proposal->name }}</a>
                                                            <x-ui.enum-badge :case="$agreement->proposal->status" />
                                                        </dd>
                                                    </div>
                                                    <div class="grid grid-cols-2 gap-3">
                                                        <div>
                                                            <dt class="font-semibold {{ $mutedText }}">{{ __('common.internal_project_number') }}</dt>
                                                            <dd class="mt-1 font-bold {{ $inkText }}"><x-ui.project-number :number="$agreement->proposal->internal_project_number" kind="internal" /></dd>
                                                        </div>
                                                        <div>
                                                            <dt class="font-semibold {{ $mutedText }}">{{ __('funding_agreements.external_project_number') }}</dt>
                                                            <dd class="mt-1 font-bold {{ $inkText }}"><x-ui.project-number :number="$agreement->external_project_number" kind="external" /></dd>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <dt class="font-semibold {{ $mutedText }}">{{ __('funding_agreements.projects_funded') }}</dt>
                                                        <dd class="mt-1 flex flex-wrap gap-x-3 gap-y-1">
                                                            @forelse ($projects as $project)
                                                                <a href="{{ route('projects.show', $project) }}" wire:navigate class="inline-flex items-center gap-1.5 font-bold underline-offset-4 hover:underline {{ $inkText }}" wire:key="agreement-{{ $agreement->id }}-project-{{ $project->id }}">
                                                                    <span class="size-1.5 shrink-0 rounded-full border border-secondary-dark dark:border-ink-muted" aria-hidden="true"></span>{{ $project->name }}
                                                                </a>
                                                            @empty
                                                                <span class="{{ $mutedText }}">{{ __('funding_agreements.no_projects_linked_yet') }}</span>
                                                            @endforelse
                                                        </dd>
                                                    </div>
                                                    <div>
                                                        <dt class="font-semibold {{ $mutedText }}">{{ __('funding_agreements.lands_in') }}</dt>
                                                        <dd class="mt-1 font-bold {{ $inkText }}">{{ $money['accounts'] !== [] ? implode(', ', $money['accounts']) : __('funding_agreements.no_account_assigned') }}</dd>
                                                    </div>
                                                    @foreach ($money['otherCurrencies'] as $otherCurrency => $otherAmount)
                                                        <p class="tabular-nums {{ $mutedText }}">{{ __('funding_agreements.also_landed_in', ['amount' => $amount($otherAmount), 'currency' => $otherCurrency]) }}</p>
                                                    @endforeach
                                                </dl>

                                                <div class="min-w-0">
                                                    @if ($agreement->transfers->isEmpty())
                                                        <p class="text-xs {{ $mutedText }}">{{ __('funding_agreements.no_tranches_scheduled_yet') }}</p>
                                                    @else
                                                        <div class="@container">
                                                            <table class="w-full text-xs @max-xl:block">
                                                                <caption class="sr-only">{{ __('funding_agreements.tranches_of_name', ['name' => $agreementName]) }}</caption>
                                                                <thead class="@max-xl:hidden">
                                                                    <tr class="text-start {{ $mutedText }}">
                                                                        <th scope="col" class="pb-2 text-start font-semibold">{{ __('funding_agreements.due') }}</th>
                                                                        <th scope="col" class="pb-2 text-end font-semibold">{{ __('funding_agreements.amount') }}</th>
                                                                        <th scope="col" class="pb-2 ps-4 text-start font-semibold">{{ __('common.status') }}</th>
                                                                        <th scope="col" class="pb-2 text-start font-semibold">{{ __('funding_agreements.sent') }}</th>
                                                                        <th scope="col" class="pb-2 text-end font-semibold">{{ __('funding_agreements.landed') }}</th>
                                                                        <th scope="col" class="pb-2 ps-4 text-start font-semibold">{{ __('funding_agreements.account') }}</th>
                                                                    </tr>
                                                                </thead>
                                                                <tbody class="[&>tr>td]:@max-xl:block [&>tr>td]:@max-xl:whitespace-normal [&>tr>td]:@max-xl:before:block [&>tr>td]:@max-xl:before:text-[11px] [&>tr>td]:@max-xl:before:font-semibold [&>tr>td]:@max-xl:before:content-[attr(data-label)] [&>tr>td]:@max-xl:before:text-secondary-medium dark:[&>tr>td]:@max-xl:before:text-secondary-soft [&>tr>td]:@max-xl:p-0 [&>tr]:@max-xl:grid [&>tr]:@max-xl:grid-cols-2 [&>tr]:@max-xl:gap-x-4 [&>tr]:@max-xl:gap-y-2 [&>tr]:@max-xl:py-3 @max-xl:block">
                                                                    @foreach ($agreement->transfers as $transfer)
                                                                        @php
                                                                            $state = $this->trancheState($transfer);
                                                                        @endphp
                                                                        <tr
                                                                            class="border-t transition-colors duration-300 {{ $hairline }}"
                                                                            :class="focusTranche === {{ $transfer->id }} && 'bg-white shadow-[inset_3px_0_0_var(--color-primary)] dark:bg-white/5'"
                                                                            wire:key="ledger-row-{{ $transfer->id }}"
                                                                            data-ledger-row="{{ $state }}"
                                                                        >
                                                                            <td data-label="{{ __('funding_agreements.due') }}" class="py-2 pe-3 font-semibold whitespace-nowrap tabular-nums {{ $inkText }}">{{ $transfer->scheduled_date?->translatedFormat(__('formats.date')) ?? '—' }}</td>
                                                                            <td data-label="{{ __('funding_agreements.amount') }}" class="py-2 pe-3 text-end font-bold whitespace-nowrap tabular-nums {{ $inkText }}">{{ $amount((float) $transfer->payment_amount) }} <span class="font-semibold {{ $mutedText }}">{{ $transfer->amount_transfered_currency->value }}</span></td>
                                                                            <td data-label="{{ __('common.status') }}" class="py-2 ps-4 pe-3 whitespace-nowrap">
                                                                                <span class="inline-flex items-center gap-1.5 rounded-full py-0.5 ps-1.5 pe-2 text-[11px] font-bold {{ $tagClass[$state] }}">
                                                                                    <span class="size-2 rounded-full {{ $beadClass[$state] }}" aria-hidden="true"></span>{{ $trancheStateLabels[$state] }}
                                                                                </span>
                                                                            </td>
                                                                            <td data-label="{{ __('funding_agreements.sent') }}" class="py-2 pe-3 whitespace-nowrap tabular-nums {{ $mutedText }}">{{ $transfer->transfer_date?->translatedFormat(__('formats.date')) ?? '—' }}</td>
                                                                            <td data-label="{{ __('funding_agreements.landed') }}" class="py-2 pe-3 text-end whitespace-nowrap tabular-nums">
                                                                                @if ($state === 'received' && $transfer->net_amount_received !== null)
                                                                                    <span class="font-bold text-funding">{{ $amount((float) $transfer->net_amount_received) }} {{ $transfer->amount_received_currency->value }}</span>
                                                                                    @if ($transfer->amount_received !== null)
                                                                                        <span class="block text-[11px] {{ $mutedText }}">{{ __('funding_agreements.gross_amount', ['amount' => $amount((float) $transfer->amount_received)]) }}</span>
                                                                                    @endif
                                                                                    @if ((float) $transfer->fees > 0)
                                                                                        <span class="block text-[11px] {{ $mutedText }}">{{ __('funding_agreements.fees_amount', ['amount' => $amount((float) $transfer->fees), 'currency' => $transfer->fees_currency->value]) }}</span>
                                                                                    @endif
                                                                                @elseif ($transfer->isMissingNetAmount())
                                                                                    <span class="font-semibold text-status-warn">{{ __('funding_agreements.net_not_recorded') }}</span>
                                                                                @else
                                                                                    <span class="{{ $mutedText }}">—</span>
                                                                                @endif
                                                                            </td>
                                                                            <td data-label="{{ __('funding_agreements.account') }}" class="py-2 ps-4 {{ $inkText }}">{{ $transfer->account?->account_name ?? __('funding_agreements.no_account_assigned') }}</td>
                                                                        </tr>
                                                                    @endforeach
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                    @endif
                                                    <div class="mt-4 flex flex-wrap items-center gap-2">
                                                        <x-ui.button tone="secondary" size="sm" icon="eye" :href="route('funding-agreements.show', $agreement)" wire:navigate>{{ __('funding_agreements.open_agreement') }}</x-ui.button>
                                                        <button type="button" x-on:click="open = null" class="inline-flex min-h-8 items-center rounded-md px-2 text-xs font-semibold hover:underline focus-visible:outline-2 focus-visible:outline-primary {{ $mutedText }}">{{ __('common.close') }}</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    </div>
                </div>

                <div class="mt-4">
                    <flux:pagination :paginator="$this->agreements" class="text-secondary-medium! dark:text-ink-muted!" scroll-to="#agreement-directory-heading" />
                </div>
            @endif
        </section>
