{{--
    The cash-flow calendar of the Overview view of the funding agreements
    page: the stage chips, then one lane per agreement on a shared month grid.
    Included by _overview.blade.php. It defines its own formatting variables,
    so it reads nothing from the page except $this and $stage.
--}}
@php
    $mutedText = 'text-secondary-medium dark:text-ink-muted';
    $inkText = 'text-secondary-dark dark:text-ink';
    $hairline = 'border-secondary-soft dark:border-line';
    $amount = fn (float $value): string => \Illuminate\Support\Number::format($value, maxPrecision: 0);
    $shortAmount = fn (float $value): string => \Illuminate\Support\Number::abbreviate($value, maxPrecision: 1);
    $calendar = $this->calendar;
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
        'late' => 'bg-primary dark:bg-status-critical ring-4 ring-secondary-light',
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
                    <button type="button" x-on:click="goToToday()" class="inline-flex min-h-9 items-center gap-1.5 rounded-lg border px-3 text-xs font-semibold transition-colors hover:bg-board-head focus-visible:outline-2 focus-visible:outline-secondary-medium dark:hover:bg-white/5 {{ $hairline }} {{ $inkText }}" data-jump-today>
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
                            'inline-flex min-h-9 items-center gap-2 rounded-full border ps-3 pe-3.5 text-xs font-semibold transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium disabled:cursor-default disabled:opacity-45',
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
                                                            class="absolute inset-x-1 inset-y-1 flex items-end gap-1.5 rounded-md px-1 pb-1 text-start transition-colors hover:bg-board-head focus-visible:outline-2 focus-visible:outline-secondary-medium aria-pressed:bg-funding-soft dark:hover:bg-white/5"
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

                        @include('pages.funding-agreements._overview-lanes')
                    </div>
                </div>

                <div class="mt-4">
                    <flux:pagination :paginator="$this->agreements" class="text-secondary-medium! dark:text-ink-muted!" scroll-to="#agreement-directory-heading" />
                </div>
            @endif
        </section>
