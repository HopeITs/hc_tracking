{{--
    The collection ledger of the Overview view of the funding agreements page:
    one line per currency, the promise split by where its money is. Included
    by _overview.blade.php. It defines its own formatting variables, so it
    reads nothing from the page except $this and $stage.
--}}
@php
    $mutedText = 'text-secondary-medium dark:text-ink-muted';
    $inkText = 'text-secondary-dark dark:text-ink';
    $hairline = 'border-secondary-soft dark:border-line';
    $amount = fn (float $value): string => \Illuminate\Support\Number::format($value, maxPrecision: 0);
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
                                            'group -m-2 rounded-lg p-2 text-start transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-secondary-medium enabled:hover:bg-board-head disabled:cursor-default dark:enabled:hover:bg-white/5 @6xl/ledger:text-end',
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
                                            'ledger-segment h-full min-w-1.5 cursor-pointer first:rounded-s-full last:rounded-e-full transition-[filter,transform] duration-150 hover:brightness-110 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium',
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
