    <div>
        <p class="type-label {{ $muted }}">{{ __('executive_summary.how_much_of_the_portfolio_is_planned_targeted_and_reached') }}</p>

        <dl class="mt-4 grid gap-x-8 gap-y-6 @xl/ledger:grid-cols-2 @5xl/ledger:grid-cols-4">
            @foreach ($this->coverageMetrics as $metric)
                @php
                    $percentage = $this->percentageOf($metric['covered'], $metric['total']);
                @endphp

                <div @class(['min-w-0', 'border-s ps-8 '.$hairline => ! $loop->first]) wire:key="coverage-{{ $metric['key'] }}">
                    <dt class="flex items-baseline justify-between gap-2">
                        <span class="truncate type-meta font-semibold {{ $ink }}">{{ $metric['label'] }}</span>
                        <span class="shrink-0 type-label {{ $muted }}">{{ $metric['scope'] }}</span>
                    </dt>
                    <dd class="mt-1.5">
                        <p class="flex items-baseline gap-2 text-2xl leading-none font-bold tracking-tight tabular-nums {{ $percentage === null ? $muted : $ink }}">
                            {{ $percentage === null ? '—' : $percentage.'%' }}
                            <span class="type-meta font-medium {{ $muted }}">
                                {{ __('executive_summary.covered_of_total_unit', ['covered' => $metric['covered'], 'total' => $metric['total'], 'unit' => $metric['unit']]) }}
                            </span>
                        </p>
                        <div
                            class="measure-track mt-2.5 h-[3px] w-full overflow-hidden"
                            role="progressbar"
                            aria-valuenow="{{ $percentage ?? 0 }}"
                            aria-valuemin="0"
                            aria-valuemax="100"
                            aria-label="{{ $metric['label'] }}"
                        >
                            <div class="ledger-fill h-full bg-secondary-dark dark:bg-ink-muted" style="width: {{ $percentage ?? 0 }}%; --row-order: {{ $loop->index }}"></div>
                        </div>
                        <p class="mt-2 type-meta {{ $muted }}">{{ $metric['description'] }}</p>
                    </dd>
                </div>
            @endforeach
        </dl>
    </div>
