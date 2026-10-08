    <div>
        {{-- The column heads only exist where the columns do; below that the
             spread stacks and each half names itself. --}}
        <div class="hidden border-b pb-2.5 {{ $hairline }} @2xl/ledger:grid @2xl/ledger:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] @2xl/ledger:items-end @2xl/ledger:gap-x-8">
            <p class="type-label text-end {{ $muted }}">{{ __('executive_summary.committed') }}</p>
            <p class="type-label w-28 text-center {{ $muted }}">{{ __('executive_summary.gap') }}</p>
            <p class="type-label {{ $muted }}">{{ __('executive_summary.delivered') }}</p>
        </div>

        <ul class="ledger-spread relative">
            @foreach ($this->ledger as $row)
                @php
                    $isOpen = $this->openRow === $row['key'];
                    $share = $row['share'];
                    $hasGap = $share !== null && $share < 100;
                @endphp

                <li wire:key="ledger-{{ $row['key'] }}" data-ledger-row="{{ $row['key'] }}">
                    <div @class(['border-b '.$hairline, 'bg-secondary-soft/20 dark:bg-white/[0.04]' => $isOpen])>
                        <button
                            type="button"
                            wire:click="toggleRow('{{ $row['key'] }}')"
                            class="ledger-open group grid w-full grid-cols-1 items-center gap-y-3 px-1 py-5 text-start @2xl/ledger:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] @2xl/ledger:gap-x-8 @2xl/ledger:gap-y-0"
                            aria-expanded="{{ $isOpen ? 'true' : 'false' }}"
                            aria-controls="breakdown-{{ $row['key'] }}"
                        >
                            {{-- What the year promised. --}}
                            <span class="min-w-0 @2xl/ledger:text-end">
                                <span class="block truncate type-meta {{ $muted }}">{{ $row['committedLabel'] }}</span>
                                <span class="mt-1 block text-2xl leading-none font-bold tracking-tight tabular-nums {{ $ink }} @xl/ledger:text-3xl">
                                    {{ $row['kind'] === 'money' ? $amount($row['committed']) : $row['committed'] }}<span class="ms-1.5 align-baseline text-[11px] font-semibold tracking-normal {{ $muted }}">{{ $row['committedUnit'] }}</span>
                                </span>
                                <span class="mt-1.5 block truncate type-meta {{ $muted }}">{{ $row['committedNote'] }}</span>
                            </span>

                            {{-- The spine, struck with this row's gap. Stacked, it
                                 lies flat between the pair; in columns it sits on
                                 the continuous rule the spread draws behind it. --}}
                            <span class="ledger-strike relative flex items-center justify-center self-stretch @2xl/ledger:w-28 @2xl/ledger:shrink-0">
                                @if ($share === null)
                                    <span class="ledger-chip relative z-10 px-2 type-meta {{ $muted }}" aria-hidden="true">—</span>
                                @elseif ($hasGap)
                                    <span class="ledger-gap-mark ledger-chip relative z-10 flex items-baseline gap-1.5 px-2 @2xl/ledger:flex-col @2xl/ledger:items-center @2xl/ledger:gap-0.5" style="--row-order: {{ $loop->index }}">
                                        <span class="text-base leading-none font-bold tabular-nums {{ $toneInk[$row['tone']] }} @2xl/ledger:text-xl">
                                            −{{ $row['kind'] === 'money' ? $amount($row['gap']) : $row['gap'] }}
                                        </span>
                                        <span class="type-label {{ $muted }}">{{ $row['gapLabel'] }}</span>
                                    </span>
                                @else
                                    <span class="ledger-gap-mark ledger-chip relative z-10 flex items-center gap-1.5 px-2 text-status-good" style="--row-order: {{ $loop->index }}">
                                        <flux:icon.check class="size-4 shrink-0" />
                                        <span class="type-label">{{ __('executive_summary.honoured') }}</span>
                                    </span>
                                @endif
                            </span>

                            {{-- What answered it. --}}
                            <span class="min-w-0">
                                <span class="flex items-center gap-1.5 truncate type-meta {{ $muted }}">
                                    <span class="size-1.5 shrink-0 rounded-full {{ $toneFill[$row['tone']] }}" aria-hidden="true"></span>
                                    {{ $row['deliveredLabel'] }}
                                </span>
                                <span class="mt-1 flex items-baseline gap-2 text-2xl leading-none font-bold tracking-tight tabular-nums {{ $toneInk[$row['tone']] }} @xl/ledger:text-3xl">
                                    <span>{{ $row['kind'] === 'money' ? $amount($row['delivered']) : $row['delivered'] }}<span class="ms-1.5 align-baseline text-[11px] font-semibold tracking-normal {{ $muted }}">{{ $row['deliveredUnit'] }}</span></span>

                                    @if ($row['delta'] !== null && $row['delta'] !== 0)
                                        <span @class([
                                            'text-[11px] font-bold tracking-normal tabular-nums',
                                            'text-status-good' => $row['delta'] > 0,
                                            'text-status-critical' => $row['delta'] < 0,
                                        ])>
                                            {{ $row['delta'] > 0 ? '+' : '−' }}{{ abs($row['delta']) }}{{ __('executive_summary.points_short') }}
                                            <span class="sr-only">{{ __('executive_summary.points_against_prior_year', ['year' => $this->fiscalYear - 1]) }}</span>
                                        </span>
                                    @endif
                                </span>
                                <span class="mt-1.5 flex items-center gap-2 truncate type-meta {{ $muted }}">
                                    {{ $row['deliveredNote'] }}
                                    <flux:icon.chevron-down class="size-3.5 shrink-0 transition-transform duration-200 {{ $isOpen ? 'rotate-180' : '' }}" />
                                </span>
                            </span>
                        </button>

                        {{-- The commitment drawn at full length, with what answered
                             it filled in. Nothing answered it at all, and the track
                             is dashed rather than merely empty. --}}
                        <div
                            @class([
                                'mb-5 h-1 w-full overflow-hidden',
                                'measure-track' => $share !== 0,
                                'ledger-unanswered' => $share === 0,
                            ])
                            role="progressbar"
                            aria-valuenow="{{ $share ?? 0 }}"
                            aria-valuemin="0"
                            aria-valuemax="100"
                            aria-label="{{ $row['committedLabel'] }} — {{ $row['deliveredLabel'] }}"
                        >
                            <div class="flex h-full w-full">
                                @foreach ($row['bands'] as $band)
                                    <div class="ledger-fill h-full {{ $toneFill[$band['tone']] }}" style="width: {{ $band['share'] }}%; --row-order: {{ $loop->parent->index }}"></div>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    {{-- The gap, decomposed into the records that own it. --}}
                    @include('pages.executive-summary._index-breakdown')
                </li>
            @endforeach
        </ul>
    </div>
