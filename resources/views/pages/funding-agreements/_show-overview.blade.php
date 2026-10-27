{{--
    The Overview view of the agreement page: the dial and its readings, the
    timeline, each tranche as a journey and the donor reports as a list.
    Included by ⚡show.blade.php, which defines $money, $term, $journeys,
    $arcStroke, $dotClass, $amount, $currency, $agreement, $donor, $proposal,
    $coFunders and $donorAgreements.
--}}
    {{-- The promise: the dial on the start side, its readings on the end side. --}}
    <div class="grid gap-x-12 gap-y-10 pt-8 @4xl/detail:grid-cols-[minmax(0,22rem)_minmax(0,1fr)] @6xl/detail:grid-cols-[minmax(0,26rem)_minmax(0,1fr)]" data-promise>
        <figure class="relative mx-auto aspect-square w-full max-w-[22rem] @6xl/detail:max-w-[26rem]" data-dial>
            <svg viewBox="0 0 200 200" class="size-full -rotate-90 overflow-visible" role="img" aria-labelledby="dial-title">
                <title id="dial-title">{{ __('funding_agreements.dial_label', ['percent' => $money['percentLabel'], 'budget' => $amount($money['budget']), 'currency' => $currency, 'owed' => $amount($money['stillToCome'])]) }}</title>
                <defs>
                    <pattern id="dial-stripes-warn" patternUnits="userSpaceOnUse" width="5" height="5" patternTransform="rotate(45)">
                        <rect width="5" height="5" class="fill-status-warn/25" />
                        <rect width="2" height="5" class="fill-status-warn" />
                    </pattern>
                    <pattern id="dial-stripes-critical" patternUnits="userSpaceOnUse" width="5" height="5" patternTransform="rotate(45)">
                        <rect width="5" height="5" class="fill-primary/20" />
                        <rect width="2" height="5" class="fill-primary" />
                    </pattern>
                    <pattern id="dial-stripes-funding" patternUnits="userSpaceOnUse" width="5" height="5" patternTransform="rotate(45)">
                        <rect width="5" height="5" class="fill-funding/15" />
                        <rect width="1.5" height="5" class="fill-funding" />
                    </pattern>
                </defs>

                {{-- The whole promise as a track; whatever no tranche covers stays visible as this ground. --}}
                <circle cx="100" cy="100" r="84" fill="none" stroke-width="16" class="stroke-secondary-soft/70 dark:stroke-surface-2" />
                <circle cx="100" cy="100" r="72" fill="none" stroke-width="0.75" stroke-dasharray="1 2.4" class="stroke-secondary-light" />

                @foreach ($this->arcs as $arc)
                    <a href="#tranche-{{ $arc['id'] }}" class="dial-arc outline-none" style="--dial-order: {{ $loop->index }}" x-on:mouseenter="lit = {{ $arc['id'] }}" x-on:mouseleave="lit = null" x-on:focus="lit = {{ $arc['id'] }}" x-on:blur="lit = null" x-bind:data-state="lit === null ? null : (lit === {{ $arc['id'] }} ? 'lit' : 'dim')" data-arc="{{ $arc['id'] }}" data-arc-state="{{ $arc['state'] }}" aria-label="{{ $arc['label'] }}">
                        <circle cx="100" cy="100" r="84" fill="none" stroke-width="16" pathLength="100"
                            class="dial-stroke {{ $arcStroke[$arc['state']] }}"
                            style="stroke-dasharray: {{ $arc['net'] }} 100; stroke-dashoffset: {{ -$arc['start'] }};" />
                        @if ($arc['net'] < $arc['length'])
                            {{-- What fees and exchange took from a received tranche: the same arc, paler. --}}
                            <circle cx="100" cy="100" r="84" fill="none" stroke-width="16" pathLength="100"
                                class="dial-stroke stroke-funding/35"
                                style="stroke-dasharray: {{ round($arc['length'] - $arc['net'], 3) }} 100; stroke-dashoffset: {{ -round($arc['start'] + $arc['net'], 3) }};" />
                        @endif
                    </a>
                @endforeach
            </svg>

            {{-- The answer in the centre; hovering an arc swaps it for that tranche. --}}
            <figcaption class="pointer-events-none absolute inset-[22%] flex flex-col items-center justify-center text-center">
                <div x-show="lit === null" class="flex flex-col items-center">
                    <span class="text-[3.5rem] leading-none font-extrabold tracking-tight tabular-nums text-funding @6xl/detail:text-[4.25rem]" data-test="dial-percent">{{ $money['percentLabel'] }}<span class="text-3xl font-bold">%</span></span>
                    <span class="type-label mt-2 {{ $mutedText }}">{{ __('funding_agreements.landed') }}</span>
                    <span class="mt-3 text-sm font-semibold tabular-nums {{ $inkText }}">{{ $amount($money['stillToCome']) }} <span class="text-xs font-medium {{ $mutedText }}">{{ $currency }}</span></span>
                    <span class="text-xs {{ $mutedText }}">{{ __('funding_agreements.still_to_come') }}</span>
                </div>
                <div x-show="lit !== null" x-cloak class="flex flex-col items-center">
                    <span class="type-label {{ $mutedText }}" x-text="lit !== null ? tranches[lit].label : ''"></span>
                    <span class="mt-2 text-xl leading-tight font-extrabold tracking-tight tabular-nums {{ $inkText }}" x-text="lit !== null ? tranches[lit].amount : ''"></span>
                    <span class="mt-1 text-sm font-semibold" x-bind:class="lit !== null && tranches[lit].alarm ? 'text-status-critical' : '{{ $mutedText }}'" x-text="lit !== null ? tranches[lit].state : ''"></span>
                </div>
            </figcaption>
        </figure>

        <div class="flex min-w-0 flex-col justify-center">
            {{-- Readings: the parts that add back up to the promise. --}}
            <dl class="grid grid-cols-2 gap-x-8 gap-y-6 @2xl/detail:grid-cols-3" data-readings>
                <div class="col-span-2 border-b pb-5 @2xl/detail:col-span-3 {{ $hairline }}">
                    <dt class="type-label {{ $mutedText }}">{{ __('common.committed') }}</dt>
                    <dd class="mt-1.5 flex flex-wrap items-baseline gap-x-2 text-4xl leading-none font-extrabold tracking-tight tabular-nums @5xl/detail:text-5xl {{ $inkText }}" data-test="reading-committed">
                        {{ $amount($money['budget']) }}
                        <span class="text-base font-bold tracking-normal {{ $mutedText }}">{{ $currency }}</span>
                    </dd>
                </div>

                @php
                    $readings = [
                        ['key' => 'landed', 'label' => __('funding_agreements.landed'), 'value' => $money['landed'], 'swatch' => 'bg-funding', 'figure' => 'text-funding', 'note' => __('funding_agreements.percent_of_committed', ['percent' => $money['percentLabel']])],
                        ['key' => 'lost', 'label' => __('funding_agreements.fees_and_exchange'), 'value' => $money['lost'], 'swatch' => 'bg-funding/35', 'figure' => $inkText, 'note' => $money['gained'] > 0.0 ? __('funding_agreements.exchange_gained_amount', ['amount' => $amount($money['gained'])]) : __('funding_agreements.taken_on_the_way')],
                        ['key' => 'unrecorded', 'label' => __('funding_agreements.received_net_not_recorded'), 'value' => $money['unrecorded'], 'swatch' => 'money-unrecorded', 'figure' => 'text-status-warn', 'note' => trans_choice('funding_agreements.count_tranches', $money['unrecordedCount'], ['count' => $money['unrecordedCount']])],
                        ['key' => 'on-its-way', 'label' => __('funding_agreements.on_its_way'), 'value' => $money['upcoming'] + $money['transit'], 'swatch' => 'money-pending', 'figure' => $inkText, 'note' => $money['nextDue'] !== null ? __('funding_agreements.next_due_on', ['date' => $money['nextDue']->translatedFormat(__('formats.date'))]) : __('funding_agreements.nothing_on_its_way')],
                        ['key' => 'late', 'label' => __('funding_agreements.late'), 'value' => $money['late'], 'swatch' => 'bg-primary dark:bg-status-critical', 'figure' => $money['late'] > 0 ? 'text-status-critical' : $inkText, 'note' => $money['lateSince'] !== null ? __('funding_agreements.due_since', ['date' => $money['lateSince']->translatedFormat(__('formats.date'))]) : __('funding_agreements.nothing_late')],
                        ['key' => 'failed', 'label' => __('funding_agreements.stage_failed'), 'value' => $money['failed'], 'swatch' => 'money-failed', 'figure' => 'text-status-critical', 'note' => __('funding_agreements.still_owed')],
                        ['key' => 'unscheduled', 'label' => __('funding_agreements.not_scheduled_yet'), 'value' => $money['unscheduled'], 'swatch' => 'bg-secondary-soft dark:bg-line-strong', 'figure' => $mutedText, 'note' => __('funding_agreements.no_date_agreed')],
                    ];
                    // Unrecorded and failed money only get a reading when there is some.
                    $readings = array_values(array_filter($readings, fn (array $reading): bool => ! in_array($reading['key'], ['unrecorded', 'failed'], true) || $reading['value'] > 0.0));
                @endphp

                @foreach ($readings as $reading)
                    <div class="logframe-reveal min-w-0" style="--logframe-order: {{ $loop->index }}" data-reading="{{ $reading['key'] }}">
                        <dt class="flex items-center gap-2">
                            <span class="size-2.5 shrink-0 rounded-full {{ $reading['swatch'] }}" aria-hidden="true"></span>
                            <span class="type-label truncate {{ $mutedText }}">{{ $reading['label'] }}</span>
                        </dt>
                        <dd class="mt-1.5 truncate text-2xl leading-none font-extrabold tracking-tight tabular-nums @5xl/detail:text-[1.75rem] {{ $reading['figure'] }}">{{ $amount($reading['value']) }}</dd>
                        <dd class="mt-1.5 truncate text-xs {{ $mutedText }}">{{ $reading['note'] }}</dd>
                    </div>
                @endforeach
            </dl>

            @if ($money['foreignLandings'] !== [] || $money['foreignCount'] > 0)
                <div class="mt-5 flex flex-col gap-1 text-xs {{ $mutedText }}" data-foreign>
                    @foreach ($money['foreignLandings'] as $foreignCode => $foreignTotal)
                        <p class="font-semibold tabular-nums text-funding">{{ __('funding_agreements.also_landed_in', ['amount' => $amount($foreignTotal), 'currency' => $foreignCode]) }}</p>
                    @endforeach
                    @if ($money['foreignCount'] > 0)
                        <p>{{ trans_choice('funding_agreements.count_tranches_sent_in_another_currency', $money['foreignCount'], ['count' => $money['foreignCount']]) }}</p>
                    @endif
                </div>
            @endif

            @include('pages.funding-agreements._show-attention')
        </div>
    </div>

    {{-- The whole agreement in time: proposal, funded delivery, tranches and reports. --}}
    <section class="mt-14 border-t pt-8 {{ $hairline }}" aria-labelledby="agreement-timeline-heading" data-agreement-timeline x-data="{ open: true }">
        <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" class="flex w-full flex-wrap items-baseline justify-between gap-x-4 gap-y-1 text-start">
            <span>
                <span id="agreement-timeline-heading" class="block text-xl font-bold tracking-tight {{ $inkText }}">{{ __('gantt.timeline') }}</span>
                <span class="block text-sm {{ $mutedText }}">{{ __('gantt.timeline_subtitle') }}</span>
            </span>
            <flux:icon.chevron-down variant="micro" class="size-4 shrink-0 transition-transform {{ $mutedText }}" x-bind:class="open && 'rotate-180'" />
        </button>
        <div x-show="open" x-collapse class="mt-5 overflow-hidden rounded-xl border {{ $hairline }}">
            @if ($this->ganttPayload['rows'] === [])
                <x-ui.empty-state icon="chart-bar" :heading="__('gantt.nothing_to_plan')" :body="__('gantt.nothing_to_plan_body')" />
            @else
                <x-ui.gantt :payload="$this->ganttPayload" :scope="'agreement-'.$this->agreementId" height="h-[min(60vh,32rem)]" wire:ignore wire:key="agreement-gantt-{{ $this->agreementId }}" />
            @endif
        </div>
    </section>

    {{-- Below the promise: each tranche's journey beside the records this money is tied to. --}}
    <div class="mt-14 grid gap-x-12 gap-y-14 border-t pt-8 @5xl/detail:grid-cols-[minmax(0,1fr)_minmax(0,22rem)] {{ $hairline }}">
        <section class="min-w-0" aria-labelledby="tranches-heading" data-tranches>
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <h2 id="tranches-heading" class="text-xl font-bold tracking-tight {{ $inkText }}">
                    {{ __('funding_agreements.tranches') }}
                    <span class="ms-1 text-base font-semibold tabular-nums {{ $mutedText }}">{{ count($journeys) }}</span>
                </h2>
                <p class="flex flex-wrap items-baseline gap-x-3 text-xs {{ $mutedText }}">
                    <span>{{ __('funding_agreements.from_schedule_to_account') }}</span>
                    @if ($journeys !== [])
                        <a href="{{ route('transfers.index', ['agreement' => $agreement->id]) }}" wire:navigate class="font-semibold underline-offset-4 hover:underline {{ $inkText }}" data-transfers-ledger-link>{{ __('funding_agreements.open_in_transfers_ledger') }}</a>
                    @endif
                </p>
            </div>

            @if ($journeys === [])
                <div class="mt-6">
                    <x-ui.empty-state class="rtl:[&_svg]:-scale-x-100" icon="arrow-right-circle" :heading="__('funding_agreements.no_tranches_scheduled')" :body="__('funding_agreements.payments_scheduled_under_this_agreement_will_appear_here')" />
                </div>
            @else
                <ol class="mt-4 flex flex-col">
                    @foreach ($journeys as $journey)
                        @php
                            $transfer = $journey['transfer'];
                            $state = $journey['state'];
                            $isReceived = $state === 'received';
                            $feesShown = (float) $transfer->fees > 0.0;
                            $rate = rtrim(rtrim((string) $transfer->exchange_rate, '0'), '.');
                        @endphp
                        <li
                            id="tranche-{{ $transfer->id }}"
                            class="tranche-row -mx-3 scroll-mt-24 border-b px-3 py-5 last:border-b-0 {{ $hairline }}"
                            x-on:mouseenter="lit = {{ $transfer->id }}"
                            x-on:mouseleave="lit = null"
                            x-on:focusin="lit = {{ $transfer->id }}"
                            x-on:focusout="lit = null"
                            x-bind:data-state="lit === {{ $transfer->id }} ? 'lit' : null"
                            wire:key="agreement-transfer-{{ $transfer->id }}"
                            data-transfer="{{ $transfer->id }}"
                            data-tranche-state="{{ $state }}"
                        >
                            <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-2">
                                <div class="min-w-0">
                                    <p class="type-label flex items-center gap-2 {{ $mutedText }}">
                                        <span class="size-2 rounded-full {{ $dotClass[$state] }}" aria-hidden="true"></span>
                                        {{ __('funding_agreements.tranche_number', ['number' => $journey['number'], 'total' => count($journeys)]) }}
                                    </p>
                                    <p class="mt-1.5 text-2xl leading-none font-extrabold tracking-tight tabular-nums {{ $inkText }}">
                                        {{ $amount((float) $transfer->payment_amount) }}
                                        <span class="text-sm font-bold tracking-normal {{ $mutedText }}">{{ $transfer->amount_transfered_currency->value }}</span>
                                    </p>
                                    @if ($journey['timing'] !== null)
                                        <p @class(['mt-1.5 text-xs', 'font-bold text-status-critical' => $journey['timingAlarm'], $mutedText => ! $journey['timingAlarm']])>{{ $journey['timing'] }}</p>
                                    @endif
                                </div>

                                <div class="flex shrink-0 items-center gap-1">
                                    <x-ui.enum-badge :case="$transfer->ar_status" />
                                    @can('transfers.update')
                                    <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9! touch-manipulation" wire:click="openTransferEdit({{ $transfer->id }})" :aria-label="__('funding_agreements.edit_tranche_number', ['number' => $journey['number']])" data-test="edit-transfer-{{ $transfer->id }}" />
                                    @endcan
                                </div>
                            </div>

                            {{-- The journey: four stops from the promise of a date to money in an account. --}}
                            <ol class="mt-5 grid grid-cols-4" aria-label="{{ __('funding_agreements.journey_of_tranche', ['number' => $journey['number']]) }}">
                                @foreach ($journey['stops'] as $stop)
                                    <li class="relative flex min-w-0 flex-col gap-1 pe-2" data-stop="{{ $stop['key'] }}" data-stop-state="{{ $stop['state'] }}">
                                        <span class="relative flex h-3 items-center" aria-hidden="true">
                                            @unless ($loop->last)
                                                <span @class([
                                                    'absolute start-2.5 end-0 h-0.5',
                                                    'bg-funding' => $isReceived && $journey['stops'][$loop->index + 1]['state'] === 'done',
                                                    'bg-secondary-dark dark:bg-ink-muted' => ! $isReceived && $stop['state'] === 'done' && $journey['stops'][$loop->index + 1]['state'] === 'done',
                                                    'bg-primary dark:bg-status-critical' => in_array($journey['stops'][$loop->index + 1]['state'], ['late', 'failed'], true),
                                                    'bg-secondary-soft dark:bg-line-strong' => ! ($stop['state'] === 'done' && $journey['stops'][$loop->index + 1]['state'] === 'done') && ! in_array($journey['stops'][$loop->index + 1]['state'], ['late', 'failed'], true),
                                                ])></span>
                                            @endunless
                                            <span @class([
                                                'relative z-10 size-3 rounded-full',
                                                'bg-funding' => $stop['state'] === 'done' && $isReceived,
                                                'bg-secondary-dark dark:bg-ink-muted' => $stop['state'] === 'done' && ! $isReceived,
                                                'bg-primary dark:bg-status-critical ring-4 ring-secondary-light' => in_array($stop['state'], ['late', 'failed'], true),
                                                'border-2 border-secondary-soft bg-white dark:border-line-strong dark:bg-surface-1' => $stop['state'] === 'ahead',
                                            ])></span>
                                        </span>
                                        <span @class([
                                            'mt-1 truncate text-[11px] leading-tight font-semibold',
                                            'text-status-critical' => in_array($stop['state'], ['late', 'failed'], true),
                                            $inkText => $stop['state'] === 'done',
                                            $mutedText => $stop['state'] === 'ahead',
                                        ])>{{ $stop['label'] }}</span>
                                        <span class="truncate text-[11px] leading-tight tabular-nums {{ $mutedText }}">
                                            @if ($stop['detail'] !== null)
                                                {{ $stop['detail'] }}
                                            @elseif ($stop['key'] === 'account')
                                                <span class="italic">{{ __('funding_agreements.no_account_assigned') }}</span>
                                            @elseif ($stop['state'] === 'late')
                                                {{ __('funding_agreements.not_yet') }}
                                            @elseif ($stop['state'] === 'failed')
                                                {{ __('funding_agreements.tranche_state_failed') }}
                                            @else
                                                —
                                            @endif
                                        </span>
                                    </li>
                                @endforeach
                            </ol>

                            {{-- The money traced: what was sent, what the way cost, what landed, and what is left of the promise. --}}
                            <div class="mt-5 flex flex-wrap items-baseline justify-between gap-x-6 gap-y-2 text-xs tabular-nums {{ $mutedText }}">
                                <p class="flex flex-wrap items-baseline gap-x-2 gap-y-1" data-trace>
                                    <span>{{ __('funding_agreements.sent') }} <span class="font-semibold {{ $inkText }}">{{ $amount((float) $transfer->payment_amount) }}</span></span>
                                    @if ($feesShown)
                                        <span aria-hidden="true">−</span>
                                        <span>{{ __('funding_agreements.fees_amount', ['amount' => $amount((float) $transfer->fees), 'currency' => $transfer->fees_currency->value]) }}</span>
                                    @endif
                                    @if ($rate !== '1')
                                        <span aria-hidden="true">×</span>
                                        <span>{{ __('funding_agreements.rate_rate', ['rate' => $rate]) }}</span>
                                    @endif
                                    <span aria-hidden="true">=</span>
                                    @if ($isReceived && $transfer->net_amount_received !== null)
                                        <span class="font-bold text-funding">{{ __('funding_agreements.net_amount_landed', ['amount' => $amount((float) $transfer->net_amount_received), 'currency' => $transfer->amount_received_currency->value]) }}</span>
                                        @if ($transfer->amount_received !== null)
                                            <span data-gross>({{ __('funding_agreements.gross_amount', ['amount' => $amount((float) $transfer->amount_received)]) }})</span>
                                        @endif
                                    @elseif ($transfer->isMissingNetAmount())
                                        <span class="font-semibold text-status-warn" data-net-missing>{{ __('funding_agreements.net_not_recorded') }}</span>
                                    @else
                                        <span class="italic">{{ __('funding_agreements.nothing_landed_yet') }}</span>
                                    @endif
                                </p>
                                @if ($journey['left'] !== null)
                                    <p data-left>{{ __('funding_agreements.left_of_the_promise') }} <span class="font-semibold {{ $inkText }}">{{ $amount(max(0.0, $journey['left'])) }}</span></p>
                                @endif
                            </div>
                        </li>
                    @endforeach

                    @if ($money['unscheduled'] > 0.0)
                        <li class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1 py-5" data-unscheduled-line>
                            <p class="flex items-center gap-2 text-sm {{ $mutedText }}">
                                <span class="size-2 rounded-full border-2 border-dashed border-secondary-light" aria-hidden="true"></span>
                                {{ __('funding_agreements.not_scheduled_yet') }}
                                <span class="font-bold tabular-nums {{ $inkText }}">{{ $amount($money['unscheduled']) }} {{ $currency }}</span>
                            </p>
                            @can('transfers.create')
                            <button type="button" wire:click="openTransferCreate({{ $agreement->id }})" class="min-h-9 touch-manipulation text-xs font-semibold underline underline-offset-4 {{ $inkText }}">{{ __('funding_agreements.schedule_the_rest') }}</button>
                            @endcan
                        </li>
                    @endif
                </ol>
            @endif

            {{-- What the donor is owed back: every report, by when, and whether it went out. --}}
            <section class="mt-12 border-t pt-8 {{ $hairline }}" aria-labelledby="reports-heading" data-donor-reports>
                <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-2">
                    <h2 id="reports-heading" class="text-xl font-bold tracking-tight {{ $inkText }}">
                        {{ __('funding_agreements.donor_reports') }}
                        <span class="ms-1 text-base font-semibold tabular-nums {{ $mutedText }}">{{ $this->reports->count() }}</span>
                    </h2>
                    @can('funding-agreements.update')
                    <x-ui.button size="sm" icon="plus" class="min-h-10 touch-manipulation" wire:click="openReportCreate" data-test="add-donor-report">{{ __('funding_agreements.add_report') }}</x-ui.button>
                    @endcan
                </div>

                @if ($this->reports->isEmpty())
                    <p class="mt-4 text-sm {{ $mutedText }}">{{ __('funding_agreements.no_donor_reports_yet') }}</p>
                @else
                    <ol class="mt-4 flex flex-col">
                        @foreach ($this->reports as $report)
                            @php
                                $reportDays = (int) \Carbon\CarbonImmutable::today()->diffInDays($report->due_on, false);
                                $reportOverdue = $report->isOverdue();
                            @endphp
                            <li class="flex flex-wrap items-start justify-between gap-x-6 gap-y-2 border-b py-4 last:border-b-0 {{ $hairline }}" wire:key="donor-report-{{ $report->id }}" data-donor-report="{{ $report->id }}">
                                <div class="flex min-w-0 items-start gap-3">
                                    <span @class([
                                        'mt-1.5 size-2.5 shrink-0 rounded-full',
                                        'bg-funding' => $report->isSubmitted(),
                                        'bg-primary dark:bg-status-critical' => $reportOverdue,
                                        'border-2 border-status-warn' => ! $report->isSubmitted() && ! $reportOverdue,
                                    ]) aria-hidden="true"></span>
                                    <div class="min-w-0">
                                        <p class="text-sm font-bold break-words {{ $inkText }}">{{ $report->title }}</p>
                                        <p class="mt-0.5 text-xs tabular-nums {{ $mutedText }}">
                                            {{ $report->type->label() }} · {{ __('funding_agreements.due_date', ['date' => $report->due_on->translatedFormat(__('formats.date'))]) }}
                                        </p>
                                        <p @class([
                                            'mt-1 text-xs tabular-nums',
                                            'text-funding' => $report->isSubmitted(),
                                            'font-bold text-status-critical' => $reportOverdue,
                                            $mutedText => ! $report->isSubmitted() && ! $reportOverdue,
                                        ]) data-report-status>
                                            @if ($report->isSubmitted())
                                                {{ __('funding_agreements.submitted_on', ['date' => $report->submitted_on->translatedFormat(__('formats.date'))]) }}
                                            @elseif ($reportOverdue)
                                                {{ trans_choice('funding_agreements.count_days_overdue', -$reportDays, ['count' => -$reportDays]) }}
                                            @else
                                                {{ trans_choice('funding_agreements.due_in_days', $reportDays, ['count' => $reportDays]) }}
                                            @endif
                                        </p>
                                    </div>
                                </div>
                                @can('funding-agreements.update')
                                <div class="flex shrink-0 items-center gap-1">
                                    @unless ($report->isSubmitted())
                                        <x-ui.button size="sm" icon="check" class="min-h-9 touch-manipulation" wire:click="markReportSubmitted({{ $report->id }})" data-test="submit-report-{{ $report->id }}">{{ __('funding_agreements.mark_submitted') }}</x-ui.button>
                                    @endunless
                                    <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9! touch-manipulation" wire:click="openReportEdit({{ $report->id }})" :aria-label="__('common.edit_name', ['name' => $report->title])" />
                                    <x-ui.button tone="ghost" size="sm" icon="trash" class="size-9! touch-manipulation" wire:click="confirmReportDelete({{ $report->id }})" :aria-label="__('funding_agreements.delete_report_name', ['name' => $report->title])" data-test="delete-report-{{ $report->id }}" />
                                </div>
                                @endcan
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>
        </section>

        {{-- The thread: every record this money is tied to, each one link away. --}}
        @include('pages.funding-agreements._show-connections')
    </div>
