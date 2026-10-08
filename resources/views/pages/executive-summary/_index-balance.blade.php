    <div class="grid gap-x-10 gap-y-7 @2xl/ledger:grid-cols-3">
        @foreach ($this->balance as $item)
            @php
                $share = $item['share'];
                $isMoney = $item['currency'] !== null;
            @endphp

            <div @class(['min-w-0', '@2xl/ledger:border-s @2xl/ledger:ps-8 '.$hairline => ! $loop->first]) wire:key="balance-{{ $item['key'] }}">
                <p class="type-label {{ $muted }}">{{ $item['label'] }}</p>

                <p class="mt-2 flex items-baseline gap-2.5 text-3xl leading-none font-extrabold tracking-tight tabular-nums @sm/ledger:text-4xl @xl/ledger:text-5xl {{ $share === null ? $muted : $toneInk[$item['tone']] }}">
                    @if ($share === null)
                        <span aria-hidden="true">—</span>
                        <span class="sr-only">{{ __('ui.no_records') }}</span>
                    @else
                        <span>{{ $share }}<span class="text-xl font-bold tracking-normal @xl/ledger:text-2xl">%</span></span>
                    @endif

                    @if ($item['delta'] !== null && $item['delta'] !== 0)
                        <span @class([
                            'inline-flex items-baseline gap-0.5 text-xs font-bold tracking-normal tabular-nums',
                            'text-status-good' => $item['delta'] > 0,
                            'text-status-critical' => $item['delta'] < 0,
                        ])>
                            {{ $item['delta'] > 0 ? '+' : '−' }}{{ abs($item['delta']) }}
                            <span class="sr-only">{{ __('executive_summary.points_against_prior_year', ['year' => $this->fiscalYear - 1]) }}</span>
                            <span aria-hidden="true" class="font-semibold">{{ __('executive_summary.points_short') }}</span>
                        </span>
                    @endif
                </p>

                <p class="mt-2.5 type-meta {{ $muted }}">
                    @if ($isMoney)
                        {{ __('executive_summary.amount_of_amount_unit', [
                            'covered' => $amount($item['covered']),
                            'total' => $amount($item['total']),
                            'unit' => $item['unit'],
                        ]) }}
                    @else
                        {{ __('executive_summary.covered_of_total_unit', [
                            'covered' => $item['covered'],
                            'total' => $item['total'],
                            'unit' => $item['unit'],
                        ]) }}
                    @endif
                </p>

                <div class="measure-track mt-3.5 h-[3px] w-full overflow-hidden" role="presentation">
                    <div
                        class="ledger-fill h-full {{ $toneFill[$item['tone']] }}"
                        style="width: {{ $share ?? 0 }}%; --row-order: {{ $loop->index }}"
                    ></div>
                </div>
            </div>
        @endforeach
    </div>
