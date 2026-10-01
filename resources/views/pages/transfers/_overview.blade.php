{{--
    The Overview view of the transfers page: the per-currency shortfall ledger
    and the ladder of tranches. Included by ⚡index.blade.php, which defines the
    formatting closures ($amount, $exact, $percent, $amountAtLeast, $rate),
    $sortOptions and $filtersAreActive this markup reads.
--}}
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
                    $shortfallPercent = $percent($line['shrink'], $line['settledSent']);
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
                            'note' => __('transfers.percent_of_settled', ['percent' => $percent($line['fees'], $line['settledSent'])]),
                            'count' => $line['shortCount'],
                        ],
                        [
                            'stage' => null,
                            'value' => $line['exchangeGap'],
                            'label' => __('transfers.exchange_gap'),
                            'dot' => 'money-gap',
                            'tone' => $inkText,
                            'note' => __('transfers.percent_of_settled', ['percent' => $percent($line['exchangeGap'], $line['settledSent'])]),
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
                                    <span x-data="countUp({{ (int) round($line['expected']) }})" x-text="new Intl.NumberFormat('en-US').format(display)">{{ $amount($line['expected']) }}</span><span class="ms-2 align-top text-sm font-bold tracking-normal {{ $mutedText }}">{{ $line['currency'] }}</span>
                                </p>
                                <p class="mt-1 text-[11px] tabular-nums {{ $mutedText }}">
                                    {{ __('transfers.of_which_sent', ['amount' => $amount($line['sent'])]) }}
                                </p>
                            </div>

                            <div class="min-w-0">
                                <p class="flex h-5 items-center gap-1.5 text-xs font-semibold {{ $mutedText }}">
                                    <span class="size-2 rounded-full bg-funding" aria-hidden="true"></span>{{ __('transfers.landed') }}
                                </p>
                                <p class="mt-1.5 flex items-baseline gap-2.5 text-3xl leading-none font-extrabold tracking-tight tabular-nums text-funding @sm/ledger:text-4xl @xl/ledger:text-5xl" data-ledger-landed>
                                    <span x-data="countUp({{ (int) round($line['landed']) }})" x-text="new Intl.NumberFormat('en-US').format(display)">{{ $amount($line['landed']) }}</span>
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
                                                −{{ $amountAtLeast($line['shrink']) }}<span class="text-lg font-bold tracking-normal">{{ $shortfallPercent }}%</span>
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
                                                <span class="mt-1.5 block text-xl leading-none font-extrabold tracking-tight tabular-nums @sm/ledger:text-2xl {{ $figure['tone'] }}">{{ $amountAtLeast($figure['value']) }}</span>
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
                                                <span @class(['mt-1.5 block text-xl leading-none font-extrabold tracking-tight tabular-nums @sm/ledger:text-2xl', $figure['tone'] => $figure['value'] > 0, $mutedText.' opacity-60' => $figure['value'] <= 0])>{{ $amountAtLeast($figure['value']) }}</span>
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
                                    $segmentTitle = $segment['label'].' · '.$exact($segment['value']).' '.$line['currency'];
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
                                    {{ trans_choice('transfers.count_tranches_landed_in_another_currency', $line['convertedCount'], ['count' => $line['convertedCount'], 'amount' => $exact($line['convertedAmount']).' '.$line['currency']]) }}
                                @endif
                                @if ($line['unrecordedCount'] > 0)
                                    {{ trans_choice('transfers.count_tranches_missing_net', $line['unrecordedCount'], ['count' => $line['unrecordedCount'], 'amount' => $exact($line['unrecordedAmount']).' '.$line['currency']]) }}
                                @endif
                            </p>
                        @endif
                    </div>
                </div>
            @empty
                <p class="text-sm {{ $mutedText }}">{{ __('transfers.no_matching_transfers') }}</p>
            @endforelse
        </section>

        {{-- How the ladder is ranked, and what is currently narrowing it. --}}
        <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-3 border-t pt-5 {{ $hairline }}">
            <div class="flex flex-wrap items-center gap-x-1 gap-y-2">
                <span class="me-2 text-xs font-semibold {{ $mutedText }}">{{ __('transfers.sort_by') }}</span>
                @foreach ($sortOptions as $sortKey => $sortLabel)
                    <button
                        type="button"
                        wire:click="setSort('{{ $sortKey }}')"
                        aria-pressed="{{ $sort === $sortKey ? 'true' : 'false' }}"
                        @class([
                            'rounded-full px-3 py-1.5 text-xs font-semibold transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                            'bg-secondary-dark text-white dark:bg-ink dark:text-surface-0' => $sort === $sortKey,
                            'text-secondary-medium hover:bg-board-head dark:text-ink-muted dark:hover:bg-white/5' => $sort !== $sortKey,
                        ])
                        data-sort="{{ $sortKey }}"
                    >{{ $sortLabel }}</button>
                @endforeach
            </div>

            <span class="text-xs tabular-nums {{ $mutedText }}">
                {{ trans_choice('transfers.count_matching_transfer', $this->transfers->total(), ['count' => $this->transfers->total()]) }}
            </span>
        </div>

        {{-- The ladder. --}}
        @if ($this->transfers->isEmpty())
            <x-ui.empty-state
                class="rtl:[&_svg]:-scale-x-100"
                icon="arrow-right-circle"
                :heading="__('transfers.no_matching_transfers')"
                :body="__('common.change_the_search_or_filters_and_try_again')"
            />
        @else
            <ul class="divide-y divide-secondary-soft dark:divide-line">
                @foreach ($this->transfers as $transfer)
                    @php
                        $sentCurrency = $transfer->amount_transfered_currency;
                        $promised = (float) $transfer->payment_amount;
                        $transferState = $transfer->state();
                        $landedHere = $transfer->amount_received_currency === $sentCurrency;
                        $netLanded = $transfer->net_amount_received === null ? null : (float) $transfer->net_amount_received;

                        // A measure is only drawn against the promise when both
                        // ends of the journey are in the same currency. A
                        // converted tranche gets its own reading instead.
                        $isComparable = $transferState === 'received' && $netLanded !== null && $landedHere;
                        // Money that did arrive, in a currency the promise cannot be
                        // measured against. It landed; it just cannot be subtracted.
                        $landedElsewhere = $transferState === 'received' && $netLanded !== null && ! $landedHere;
                        $feesHere = $transfer->fees_currency === $sentCurrency;
                        $feesAmount = (float) $transfer->fees;

                        $shrink = $isComparable ? max(0.0, $promised - $netLanded) : 0.0;
                        $feesTaken = $isComparable && $feesHere ? min($shrink, $feesAmount) : 0.0;
                        $gapTaken = $shrink - $feesTaken;
                        $overage = $isComparable ? max(0.0, $netLanded - $promised) : 0.0;

                        // The bar is scaled to whichever is larger, so a tranche
                        // that arrived fuller than it left still fits and the
                        // promise tick shows it overshot.
                        $rowScale = max($promised, $netLanded ?? 0.0, 0.01);
                        $promisedTick = round($promised / $rowScale * 100, 3);

                        $daysLate = $transferState === 'late' && $transfer->scheduled_date !== null
                            ? (int) round($transfer->scheduled_date->diffInDays(\Carbon\CarbonImmutable::today()))
                            : null;
                        // Only when the money landed after it was sent, and only
                        // when it landed at all. A receive date before the transfer
                        // date is a typo, and "landed in −3 days" reports the typo
                        // as a fact; a failed tranche never "landed" in any days.
                        $daysInTransit = $transferState === 'received'
                            && $transfer->transfer_date !== null
                            && $transfer->receive_date !== null
                            && $transfer->receive_date->gte($transfer->transfer_date)
                                ? (int) round($transfer->transfer_date->diffInDays($transfer->receive_date))
                                : null;

                        // Money the donor has sent that has not arrived. Without
                        // this the page is blind to the case it exists for: a wire
                        // sent months ago that the account never saw is only ever
                        // labelled "In transit", with no age and no urgency.
                        $daysInFlight = $transferState === 'transit' && $transfer->transfer_date !== null
                            ? (int) round($transfer->transfer_date->diffInDays(\Carbon\CarbonImmutable::today()))
                            : null;

                        $rowTone = match ($transferState) {
                            'late', 'failed' => 'text-status-critical',
                            'transit' => 'text-status-warn',
                            default => $mutedText,
                        };

                        $stateLabel = match (true) {
                            // Marked received with nothing recorded is not "landed".
                            $transfer->isMissingNetAmount() => __('transfers.not_recorded_yet'),
                            $transferState === 'received' => __('transfers.landed'),
                            $transferState === 'failed' => __('transfers.stage_failed'),
                            $transferState === 'transit' => __('transfers.stage_in_transit'),
                            $transferState === 'late' => __('transfers.stage_overdue'),
                            default => __('transfers.stage_upcoming'),
                        };

                        // The three nodes of the journey. An empty node is still
                        // drawn: a date nobody has filled in is information.
                        $thread = [
                            ['label' => __('transfers.scheduled'), 'date' => $transfer->scheduled_date, 'done' => $transfer->scheduled_date !== null],
                            ['label' => __('transfers.sent_on'), 'date' => $transfer->transfer_date, 'done' => $transfer->transfer_date !== null],
                            ['label' => __('transfers.received_on'), 'date' => $transfer->receive_date, 'done' => $transfer->receive_date !== null],
                        ];

                        $measureLabel = $isComparable || $landedElsewhere
                            ? __('transfers.measure_label_landed', [
                                'sent' => $exact($promised).' '.$sentCurrency->value,
                                'landed' => $exact($netLanded).' '.$transfer->amount_received_currency->value,
                            ])
                            : __('transfers.measure_label_pending', [
                                'sent' => $exact($promised).' '.$sentCurrency->value,
                                'state' => $stateLabel,
                            ]);
                    @endphp

                    <li wire:key="transfer-{{ $transfer->id }}" data-transfer-state="{{ $transferState }}">
                        <div
                            x-data="{ open: false }"
                            class="grid gap-x-8 gap-y-4 py-5 @4xl/ladder:grid-cols-[minmax(14rem,.85fr)_minmax(0,1.15fr)] @4xl/ladder:items-center"
                        >
                            {{-- Who owes it, and the journey its dates describe. --}}
                            <div class="min-w-0">
                                <div class="flex items-start gap-2">
                                    <span @class(['mt-1.5 size-2 shrink-0 rounded-full', match ($transferState) {
                                        'received' => 'bg-funding',
                                        'failed', 'late' => 'bg-primary dark:bg-status-critical',
                                        'transit' => 'bg-status-warn',
                                        default => 'money-pending',
                                    }]) aria-hidden="true"></span>
                                    <div class="min-w-0">
                                        <a
                                            href="{{ route('donors.show', $transfer->donorProposal->donor) }}"
                                            wire:navigate
                                            class="block truncate text-sm font-semibold underline-offset-2 hover:underline {{ $inkText }}"
                                        >{{ $transfer->donorProposal->donor->name }}</a>
                                        <p class="flex min-w-0 flex-wrap items-center gap-x-1.5 text-xs text-secondary-medium dark:text-ink-muted">
                                            <a href="{{ route('proposals.show', $transfer->donorProposal->proposal) }}" wire:navigate class="truncate underline-offset-2 hover:underline">{{ $transfer->donorProposal->proposal->name }}</a>
                                            <span aria-hidden="true">·</span>
                                            <x-ui.project-number :number="$transfer->donorProposal->proposal->internal_project_number" kind="internal" class="shrink-0" />
                                            <span aria-hidden="true">·</span>
                                            <a href="{{ route('funding-agreements.show', $transfer->donorProposal) }}" wire:navigate class="shrink-0 underline-offset-2 hover:underline" data-agreement-link>{{ filled($transfer->donorProposal->external_project_number) ? __('proposals.agreement_number', ['number' => $transfer->donorProposal->external_project_number]) : __('proposals.view_agreement') }}</a>
                                        </p>
                                    </div>
                                </div>

                                {{-- Scheduled, sent, landed — three nodes on one thread. --}}
                                <ol class="mt-3 flex items-center gap-0 ps-4">
                                    @foreach ($thread as $node)
                                        @if (! $loop->first)
                                            <li aria-hidden="true" @class(['h-px w-4 shrink-0 @xl/ladder:w-7', $node['done'] ? 'bg-secondary-light' : 'bg-secondary-soft dark:bg-line-strong'])></li>
                                        @endif
                                        <li class="flex items-center gap-1.5">
                                            <span @class([
                                                'size-1.5 shrink-0 rounded-full',
                                                'bg-secondary-dark dark:bg-ink-muted' => $node['done'],
                                                'ring-1 ring-secondary-light' => ! $node['done'],
                                            ]) aria-hidden="true"></span>
                                            {{-- secondary-light is 2.69:1 on white. The
                                                 "not yet" signal is the hollow dot, not a
                                                 colour nobody can read. --}}
                                            <span class="text-[11px] tabular-nums whitespace-nowrap {{ $mutedText }}">
                                                <span class="sr-only">{{ $node['label'] }}: </span>
                                                @if ($node['date'] === null)
                                                    <span class="sr-only">{{ __('transfers.not_yet_assigned') }}</span>
                                                    <span aria-hidden="true">—</span>
                                                @else
                                                    {{ $node['date']->translatedFormat(__('formats.date_short')) }}
                                                @endif
                                            </span>
                                        </li>
                                    @endforeach
                                </ol>

                                <p class="mt-2 ps-4 text-xs font-semibold {{ $rowTone }}">
                                    @if ($daysLate !== null)
                                        {{ trans_choice('transfers.count_days_overdue', $daysLate, ['count' => $daysLate]) }}
                                    @elseif ($daysInFlight !== null)
                                        {{ trans_choice('transfers.in_transit_for_count_days', $daysInFlight, ['count' => $daysInFlight]) }}
                                    @elseif ($daysInTransit !== null)
                                        {{ trans_choice('transfers.landed_in_count_days', $daysInTransit, ['count' => $daysInTransit]) }}
                                    @else
                                        {{ $stateLabel }}
                                    @endif
                                    <span class="{{ $mutedText }}">
                                        ·
                                        @if ($transfer->account === null)
                                            <span class="italic">{{ __('transfers.no_account') }}</span>
                                        @else
                                            {{ $transfer->account->account_name }}
                                        @endif
                                    </span>
                                </p>
                            </div>

                            {{-- The measure: the promise at full length, and what is left of it. --}}
                            <div class="min-w-0">
                                <div class="flex items-end justify-between gap-4">
                                    <p class="text-2xl leading-none font-extrabold tracking-tight tabular-nums {{ $inkText }}">
                                        {{ $amount($promised) }}<span class="ms-1.5 text-[11px] font-bold tracking-normal {{ $mutedText }}">{{ $sentCurrency->value }}</span>
                                    </p>

                                    <div class="flex items-center gap-1.5">
                                        @if ($isComparable && $shrink > 0)
                                            <p class="text-sm leading-none font-bold tabular-nums text-status-critical" data-row-shortfall>
                                                −{{ $percent($shrink, $promised) }}%
                                            </p>
                                        @elseif ($isComparable && $overage > 0)
                                            <p class="text-sm leading-none font-bold tabular-nums text-funding">
                                                +{{ $percent($overage, $promised) }}%
                                            </p>
                                        @elseif ($isComparable)
                                            <p class="text-sm leading-none font-bold tabular-nums text-funding">{{ __('transfers.landed_in_full') }}</p>
                                        @else
                                            <x-ui.enum-badge :case="$transfer->ar_status" />
                                        @endif

                                        <button
                                            type="button"
                                            x-on:click="open = ! open"
                                            {{-- Static default so the control is still described
                                                 correctly before Alpine takes over the binding. --}}
                                            aria-expanded="false"
                                            x-bind:aria-expanded="open ? 'true' : 'false'"
                                            aria-controls="transfer-detail-{{ $transfer->id }}"
                                            class="-me-1 flex size-8 items-center justify-center rounded-md text-secondary-medium transition-colors duration-150 hover:bg-board-head focus-visible:outline-2 focus-visible:outline-primary dark:text-ink-muted dark:hover:bg-white/5"
                                            aria-label="{{ __('transfers.show_the_arithmetic') }}"
                                        >
                                            <flux:icon name="chevron-down" class="size-4 transition-transform duration-200" x-bind:class="open && 'rotate-180'" />
                                        </button>

                                        @can('transfers.update')
                                        {{-- No delete: a money record never disappears. --}}
                                        <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-8!" wire:click="openEditModal({{ $transfer->id }})" :aria-label="__('transfers.edit_transfer')" />
                                        @endcan
                                    </div>
                                </div>

                                {{-- The bar is the promise, drawn at full length. What landed is solid;
                                     what the journey took is cut out of it. --}}
                                <div class="relative mt-2.5 h-4" role="img" aria-label="{{ $measureLabel }}">
                                    <div class="measure-track absolute inset-0 flex gap-[2px] overflow-hidden rounded-[3px]">
                                        @if ($isComparable)
                                            <span class="measure-fill h-full bg-funding" style="width: {{ round(($netLanded ?? 0) / $rowScale * 100, 3) }}%" title="{{ __('transfers.landed') }} · {{ $exact($netLanded) }} {{ $sentCurrency->value }}"></span>
                                            @if ($feesTaken > 0)
                                                <span class="measure-fill money-fees h-full" style="width: {{ round($feesTaken / $rowScale * 100, 3) }}%" title="{{ __('transfers.fees') }} · {{ $exact($feesTaken) }} {{ $sentCurrency->value }}"></span>
                                            @endif
                                            @if ($gapTaken > 0)
                                                <span class="measure-fill money-gap h-full" style="width: {{ round($gapTaken / $rowScale * 100, 3) }}%" title="{{ __('transfers.exchange_gap') }} · {{ $exact($gapTaken) }} {{ $sentCurrency->value }}"></span>
                                            @endif
                                        @else
                                            {{-- Nothing has landed. The measure is drawn empty rather than
                                                 omitted: an unlit slot is information, a blank row is not. --}}
                                            <span @class(['measure-fill h-full w-full', match (true) {
                                                // It arrived, just not in a currency the promise
                                                // can be measured against — so it reads as landed,
                                                // never as money nobody recorded.
                                                $landedElsewhere => 'bg-funding',
                                                $transferState === 'failed' => 'money-failed',
                                                $transferState === 'late' => 'money-overdue',
                                                $transferState === 'transit' => 'money-transit',
                                                $transferState === 'received' => 'money-unrecorded',
                                                default => 'money-pending',
                                            }]) title="{{ $measureLabel }}"></span>
                                        @endif
                                    </div>

                                    {{-- Where the promise sat, so an overshoot is visible as an overshoot. --}}
                                    @if ($isComparable && $overage > 0)
                                        <span class="absolute inset-y-0 w-px bg-secondary-dark dark:bg-ink" style="inset-inline-start: {{ $promisedTick }}%" aria-hidden="true"></span>
                                    @endif
                                </div>

                                {{-- The arithmetic that produced the gap. --}}
                                <div
                                    id="transfer-detail-{{ $transfer->id }}"
                                    x-show="open"
                                    x-collapse
                                    {{-- Hidden before Alpine boots too: twenty panels flashing open
                                         and collapsing on load is a worse first paint than none. --}}
                                    style="display: none"
                                    class="mt-3 border-t pt-3 {{ $hairline }}"
                                >
                                    <dl class="grid grid-cols-2 gap-x-6 gap-y-2.5 text-xs @xl/ladder:grid-cols-4">
                                        <div>
                                            <dt class="{{ $mutedText }}">{{ __('transfers.sent') }}</dt>
                                            <dd class="mt-0.5"><x-ui.money :amount="$transfer->payment_amount" :currency="$transfer->amount_transfered_currency" /></dd>
                                        </div>
                                        <div>
                                            <dt class="{{ $mutedText }}">{{ __('transfers.fees') }}</dt>
                                            <dd class="mt-0.5"><x-ui.money :amount="$transfer->fees" :currency="$transfer->fees_currency" /></dd>
                                        </div>
                                        <div>
                                            <dt class="{{ $mutedText }}">{{ __('transfers.exchange_rate') }}</dt>
                                            <dd class="mt-0.5 font-semibold tabular-nums">{{ $rate($transfer->exchange_rate) }}</dd>
                                        </div>
                                        <div>
                                            <dt class="{{ $mutedText }}">{{ __('common.net_received') }}</dt>
                                            <dd class="mt-0.5">
                                                @if ($transfer->net_amount_received === null)
                                                    <span class="text-status-critical">{{ __('transfers.not_recorded_yet') }}</span>
                                                @else
                                                    <x-ui.money :amount="$transfer->net_amount_received" :currency="$transfer->amount_received_currency" />
                                                @endif
                                            </dd>
                                        </div>
                                    </dl>

                                    <p class="mt-3 text-xs {{ $mutedText }}">
                                        @if ($isComparable && $shrink > 0)
                                            <span class="font-semibold text-status-critical">−{{ $exact($shrink) }} {{ $sentCurrency->value }}</span>
                                            {{ __('transfers.never_reached_the_account') }}
                                        @elseif (! $landedHere && $transfer->net_amount_received !== null)
                                            {{ __('transfers.sent_in_currency_landed_in_currency', ['sent' => $sentCurrency->value, 'landed' => $transfer->amount_received_currency->value]) }}
                                        @elseif ($transfer->isMissingNetAmount())
                                            {{ __('transfers.received_but_no_net_amount_recorded') }}
                                        @else
                                            {{ __('transfers.nothing_has_left_the_donor_yet') }}
                                        @endif
                                        <span class="inline-flex flex-wrap items-center gap-x-1.5 {{ $inkText }}">
                                            <x-ui.project-number :number="$transfer->donorProposal->proposal->internal_project_number" kind="internal" />
                                            <span aria-hidden="true">·</span>
                                            @if (filled($transfer->donorProposal->external_project_number))
                                                <span>{{ __('transfers.reference_num', ['reference' => $transfer->donorProposal->external_project_number]) }}</span>
                                            @else
                                                <span class="{{ $mutedText }}">{{ __('common.no_external_project_number') }}</span>
                                            @endif
                                        </span>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>

            <flux:pagination :paginator="$this->transfers" class="text-secondary-medium! dark:text-ink-muted!" scroll-to="#transfer-ladder-heading" />
        @endif
