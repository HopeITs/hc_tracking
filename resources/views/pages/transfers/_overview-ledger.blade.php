        {{-- The ledger: per currency, what left the donor against what actually arrived. --}}
        <section aria-labelledby="ledger-heading" class="@container/ledger" data-ledger>
            <h2 id="ledger-heading" class="sr-only">{{ __('transfers.what_was_sent_against_what_landed') }}</h2>

            @forelse ($this->ledger as $line)
                @php
                    // The shrink hairline is drawn against everything sent, so
                    // money still travelling occupies its share as a ghost
                    // rather than silently making the loss look larger. A
                    // tranche that arrived over pushes the total past what was
                    // sent, so the scale takes whichever is larger.
                    $scale = max($line['expected'], $line['landed'] + $line['shrink'] + $line['inFlight'], 0.01);
                    $shortfallPercent = $this->percent($line['shrink'], $line['settledSent']);
                    // "Landed in full" may only be said when nothing is still
                    // outstanding. Otherwise it is the same lie the per-row
                    // netting produced, reached by a different route.
                    $everythingSettled = $line['inFlight'] <= 0;

                    $figures = [
                        [
                            'stage' => null,
                            'value' => $line['overage'],
                            'label' => __('transfers.arrived_over'),
                            'dot' => 'bg-funding',
                            'tone' => 'text-funding',
                            'note' => __('transfers.more_than_was_sent'),
                            'count' => $line['overage'] > 0 ? 1 : 0,
                        ],
                        // Fees and the exchange gap are two halves of the
                        // shortfall, not two places money is stuck. Filtering
                        // both to `short` made four controls that all did the
                        // same thing and lit up together. The shortfall figure
                        // itself is the control now; these two only report.
                        [
                            'stage' => null,
                            'value' => $line['fees'],
                            'label' => __('transfers.fees'),
                            'dot' => 'money-fees',
                            'tone' => 'text-status-warn',
                            'note' => __('transfers.percent_of_settled', ['percent' => $this->percent($line['fees'], $line['settledSent'])]),
                            'count' => $line['shortCount'],
                        ],
                        [
                            'stage' => null,
                            'value' => $line['exchangeGap'],
                            'label' => __('transfers.exchange_gap'),
                            'dot' => 'money-gap',
                            'tone' => $inkText,
                            'note' => __('transfers.percent_of_settled', ['percent' => $this->percent($line['exchangeGap'], $line['settledSent'])]),
                            'count' => $line['shortCount'],
                        ],
                        [
                            'stage' => 'overdue',
                            'value' => $line['overdue'],
                            'label' => __('transfers.stage_overdue'),
                            'dot' => 'bg-primary dark:bg-status-critical',
                            'tone' => 'text-status-critical',
                            'note' => trans_choice('transfers.count_tranches', $line['overdueCount'], ['count' => $line['overdueCount']]),
                            'count' => $line['overdueCount'],
                        ],
                        [
                            'stage' => 'transit',
                            'value' => $line['transit'],
                            'label' => __('transfers.stage_in_transit'),
                            'dot' => 'bg-status-warn',
                            'tone' => 'text-status-warn',
                            'note' => trans_choice('transfers.count_tranches', $line['transitCount'], ['count' => $line['transitCount']]),
                            'count' => $line['transitCount'],
                        ],
                        [
                            'stage' => 'upcoming',
                            'value' => $line['upcoming'],
                            'label' => __('transfers.stage_upcoming'),
                            'dot' => 'money-pending',
                            'tone' => $inkText,
                            'note' => trans_choice('transfers.count_tranches', $line['upcomingCount'], ['count' => $line['upcomingCount']]),
                            'count' => $line['upcomingCount'],
                        ],
                        [
                            'stage' => 'failed',
                            'value' => $line['failed'],
                            'label' => __('transfers.stage_failed'),
                            'dot' => 'money-failed',
                            'tone' => 'text-status-critical',
                            'note' => trans_choice('transfers.count_tranches', $line['failedCount'], ['count' => $line['failedCount']]),
                            'count' => $line['failedCount'],
                        ],
                    ];

                    // A figure earns its place when it has money behind it, or
                    // while it is the active filter. Nothing is shown at zero
                    // just to keep the row a tidy width.
                    $figures = array_values(array_filter(
                        $figures,
                        fn (array $figure): bool => $figure['value'] > 0 || $stage === $figure['stage'],
                    ));

                    $segments = [
                        ['key' => 'landed', 'stage' => null, 'value' => $line['landed'], 'fill' => 'bg-funding', 'label' => __('transfers.landed')],
                        ['key' => 'fees', 'stage' => 'short', 'value' => $line['fees'], 'fill' => 'money-fees', 'label' => __('transfers.fees')],
                        ['key' => 'gap', 'stage' => 'short', 'value' => $line['exchangeGap'], 'fill' => 'money-gap', 'label' => __('transfers.exchange_gap')],
                        ['key' => 'transit', 'stage' => 'transit', 'value' => $line['transit'], 'fill' => 'bg-status-warn', 'label' => __('transfers.stage_in_transit')],
                        ['key' => 'overdue', 'stage' => 'overdue', 'value' => $line['overdue'], 'fill' => 'bg-primary dark:bg-status-critical', 'label' => __('transfers.stage_overdue')],
                        ['key' => 'upcoming', 'stage' => 'upcoming', 'value' => $line['upcoming'], 'fill' => 'money-pending', 'label' => __('transfers.stage_upcoming')],
                        ['key' => 'failed', 'stage' => 'failed', 'value' => $line['failed'], 'fill' => 'money-failed', 'label' => __('transfers.stage_failed')],
                    ];
                @endphp

                <div
                    @class(['grid gap-y-5', 'mt-8 border-t pt-8 '.$hairline => ! $loop->first])
                    wire:key="ledger-{{ $line['currency'] }}"
                    data-ledger-currency="{{ $line['currency'] }}"
                >
                    {{-- Every figure is a label line, a number, then an optional note.
                         Aligned to the start, not the end: "Expected" carries a third
                         line, and bottom-aligning pushed its number above the two
                         beside it. Start-aligned, all the labels share one line and
                         all the figures share one baseline. --}}
                    <div class="grid gap-x-10 gap-y-6 @6xl/ledger:grid-cols-[auto_minmax(0,1fr)] @6xl/ledger:items-start">
                        <div class="flex flex-wrap items-start gap-x-7 gap-y-4 @xl/ledger:gap-x-10">
                            {{-- "Expected", not "Sent": most of this may not have left
                                 the donor yet, and the rows below say so themselves. --}}
                            <div class="min-w-0">
                                <p class="flex h-5 items-center text-xs font-semibold {{ $mutedText }}">
                                    {{ __('transfers.expected') }} · {{ trans_choice('transfers.count_tranches', $line['tranches'], ['count' => $line['tranches']]) }}
                                </p>
                                <p class="mt-1.5 text-3xl leading-none font-extrabold tracking-tight tabular-nums @sm/ledger:text-4xl @xl/ledger:text-5xl {{ $inkText }}">
                                    <span x-data="countUp({{ (int) round($line['expected']) }})" x-text="new Intl.NumberFormat('en-US').format(display)">{{ $this->amount($line['expected']) }}</span><span class="ms-2 align-top text-sm font-bold tracking-normal {{ $mutedText }}">{{ $line['currency'] }}</span>
                                </p>
                                <p class="mt-1 text-[11px] tabular-nums {{ $mutedText }}">
                                    {{ __('transfers.of_which_sent', ['amount' => $this->amount($line['sent'])]) }}
                                </p>
                            </div>

                            <div class="min-w-0">
                                <p class="flex h-5 items-center gap-1.5 text-xs font-semibold {{ $mutedText }}">
                                    <span class="size-2 rounded-full bg-funding" aria-hidden="true"></span>{{ __('transfers.landed') }}
                                </p>
                                <p class="mt-1.5 flex items-baseline gap-2.5 text-3xl leading-none font-extrabold tracking-tight tabular-nums text-funding @sm/ledger:text-4xl @xl/ledger:text-5xl" data-ledger-landed>
                                    <span x-data="countUp({{ (int) round($line['landed']) }})" x-text="new Intl.NumberFormat('en-US').format(display)">{{ $this->amount($line['landed']) }}</span>
                                </p>
                            </div>

                            {{-- The one figure this page exists to show, and the
                                 control that narrows the ladder to the tranches
                                 that produced it. --}}
                            @if ($line['settledSent'] > 0)
                                @php
                                    $shortfallIsActive = $stage === 'short';
                                @endphp
                                <div class="min-w-0">
                                    <button
                                        type="button"
                                        wire:click="toggleStage('short')"
                                        aria-pressed="{{ $shortfallIsActive ? 'true' : 'false' }}"
                                        @disabled($line['shrink'] <= 0)
                                        data-ledger-figure="short"
                                        @class([
                                            'group -m-2 rounded-lg p-2 text-start transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-primary enabled:hover:bg-board-head disabled:cursor-default dark:enabled:hover:bg-white/5',
                                            'bg-board-head ring-1 ring-secondary-dark dark:bg-white/5 dark:ring-ink' => $shortfallIsActive,
                                        ])
                                    >
                                        <span class="flex h-5 items-center text-xs font-semibold {{ $mutedText }} group-enabled:group-hover:underline group-aria-pressed:underline">{{ __('transfers.shortfall') }}</span>
                                        <span @class([
                                            'mt-1.5 flex items-baseline gap-2 text-3xl leading-none font-extrabold tracking-tight tabular-nums @sm/ledger:text-4xl @xl/ledger:text-5xl',
                                            'text-status-critical' => $line['shrink'] > 0,
                                            'text-funding' => $line['shrink'] <= 0,
                                        ]) data-ledger-shortfall>
                                            @if ($line['shrink'] > 0)
                                                −{{ $this->amountAtLeast($line['shrink']) }}<span class="text-lg font-bold tracking-normal">{{ $shortfallPercent }}%</span>
                                            @elseif ($everythingSettled)
                                                <span class="text-2xl @xl/ledger:text-3xl">{{ __('transfers.landed_in_full') }}</span>
                                            @else
                                                {{-- Nothing lost so far, but money is still out there. --}}
                                                <span class="text-2xl @xl/ledger:text-3xl">{{ __('transfers.settled_money_landed_in_full') }}</span>
                                            @endif
                                        </span>
                                    </button>
                                </div>
                            @endif
                        </div>

                        {{-- Where the rest of it is. Each figure narrows the ladder below. --}}
                        @if ($figures !== [])
                            {{-- Wrapping rather than a fixed column count: a seven-digit
                                 figure in a sixth of the row overlaps its neighbour. --}}
                            <ul class="flex flex-wrap items-start gap-x-8 gap-y-4 @6xl/ledger:justify-end">
                                @foreach ($figures as $figure)
                                    @php
                                        $isActive = $figure['stage'] !== null && $stage === $figure['stage'];
                                        $isEmpty = $figure['count'] <= 0;
                                    @endphp
                                    <li class="min-w-0">
                                        @if ($figure['stage'] === null)
                                            {{-- Not a place money can be stuck, so not a filter. --}}
                                            <div class="p-2 -m-2">
                                                <span class="flex h-5 items-center gap-1.5 text-xs font-semibold {{ $mutedText }}">
                                                    <span class="size-2 shrink-0 rounded-full {{ $figure['dot'] }}" aria-hidden="true"></span>{{ $figure['label'] }}
                                                </span>
                                                <span class="mt-1.5 block text-xl leading-none font-extrabold tracking-tight tabular-nums @sm/ledger:text-2xl {{ $figure['tone'] }}">{{ $this->amountAtLeast($figure['value']) }}</span>
                                                <span class="mt-1 block text-[11px] tabular-nums {{ $mutedText }}">{{ $figure['note'] }}</span>
                                            </div>
                                        @else
                                            <button
                                                type="button"
                                                wire:click="toggleStage('{{ $figure['stage'] }}')"
                                                aria-pressed="{{ $isActive ? 'true' : 'false' }}"
                                                @disabled($isEmpty && ! $isActive)
                                                @class([
                                                    'group -m-2 w-full rounded-lg p-2 text-start transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-primary enabled:hover:bg-board-head disabled:cursor-default dark:enabled:hover:bg-white/5',
                                                    'bg-board-head ring-1 ring-secondary-dark dark:bg-white/5 dark:ring-ink' => $isActive,
                                                ])
                                                data-ledger-figure="{{ $figure['stage'] }}"
                                            >
                                                <span class="flex h-5 items-center gap-1.5 text-xs font-semibold {{ $mutedText }}">
                                                    <span class="size-2 shrink-0 rounded-full {{ $figure['dot'] }}" aria-hidden="true"></span>{{ $figure['label'] }}
                                                </span>
                                                <span @class(['mt-1.5 block text-xl leading-none font-extrabold tracking-tight tabular-nums @sm/ledger:text-2xl', $figure['tone'] => $figure['value'] > 0, $mutedText.' opacity-60' => $figure['value'] <= 0])>{{ $this->amountAtLeast($figure['value']) }}</span>
                                                <span class="mt-1 block text-[11px] tabular-nums {{ $mutedText }} group-enabled:group-hover:underline group-aria-pressed:underline">{{ $figure['note'] }}</span>
                                            </button>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    {{-- The shrink line: everything sent, split by what became of it. --}}
                    <div>
                        <div class="measure-track flex h-4 w-full gap-[3px] overflow-hidden rounded-[3px]" role="group" aria-label="{{ __('transfers.shrink_line_label', ['currency' => $line['currency']]) }}">
                            @foreach ($segments as $segment)
                                @continue($segment['value'] <= 0)
                                @php
                                    $segmentTitle = $segment['label'].' · '.$this->exact($segment['value']).' '.$line['currency'];
                                    $isActive = $segment['stage'] !== null && $stage === $segment['stage'];
                                @endphp
                                @if ($segment['stage'] === null)
                                    <span
                                        class="ledger-segment h-full min-w-1.5 rounded-[2px] {{ $segment['fill'] }}"
                                        style="width: {{ round($segment['value'] / $scale * 100, 3) }}%"
                                        title="{{ $segmentTitle }}"
                                    ><span class="sr-only">{{ $segmentTitle }}</span></span>
                                @else
                                    <button
                                        type="button"
                                        wire:click="toggleStage('{{ $segment['stage'] }}')"
                                        @class(['ledger-segment h-full min-w-1.5 rounded-[2px] transition-opacity duration-150 hover:opacity-80 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary', $segment['fill'], 'ring-1 ring-secondary-dark dark:ring-ink' => $isActive])
                                        style="width: {{ round($segment['value'] / $scale * 100, 3) }}%"
                                        title="{{ $segmentTitle }}"
                                        aria-pressed="{{ $isActive ? 'true' : 'false' }}"
                                    ><span class="sr-only">{{ $segmentTitle }}</span></button>
                                @endif
                            @endforeach
                        </div>

                        {{-- Money with no segment on the line above. Naming the
                             amount, not just the count, so the unfilled remainder
                             is accounted for rather than left as a silent gap. --}}
                        @if ($line['convertedCount'] > 0 || $line['unrecordedCount'] > 0)
                            <p class="mt-2 text-xs {{ $mutedText }}">
                                @if ($line['convertedCount'] > 0)
                                    {{ trans_choice('transfers.count_tranches_landed_in_another_currency', $line['convertedCount'], ['count' => $line['convertedCount'], 'amount' => $this->exact($line['convertedAmount']).' '.$line['currency']]) }}
                                @endif
                                @if ($line['unrecordedCount'] > 0)
                                    {{ trans_choice('transfers.count_tranches_missing_net', $line['unrecordedCount'], ['count' => $line['unrecordedCount'], 'amount' => $this->exact($line['unrecordedAmount']).' '.$line['currency']]) }}
                                @endif
                            </p>
                        @endif
                    </div>
                </div>
            @empty
                <p class="text-sm {{ $mutedText }}">{{ __('transfers.no_matching_transfers') }}</p>
            @endforelse
        </section>
