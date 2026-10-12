@use('Illuminate\Support\Number')

    {{-- Bento: attention leads, money and pulse beside it --}}
    <div class="grid grid-cols-1 gap-4 @3xl/dashboard:grid-cols-6 @5xl/dashboard:grid-cols-12">
        {{-- Needs attention: every row renders once, the filter only hides rows in the browser --}}
        <section
            @class([
                $cardClasses,
                'flex min-w-0 flex-col @3xl/dashboard:col-span-6 @5xl/dashboard:col-span-7',
                '@5xl/dashboard:row-span-2' => $canViewFundingPosition,
            ])
            aria-labelledby="attention-heading"
            x-data="{ focus: 'all' }"
            data-attention
        >
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-secondary-soft px-5 pt-4 pb-3 dark:border-line">
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <h2 id="attention-heading" class="text-base font-bold text-secondary-dark dark:text-ink">{{ __('dashboard.needs_attention') }}</h2>
                        <span @class([
                            'inline-flex min-w-6 items-center justify-center rounded-full px-2 py-0.5 text-[11px] font-bold tabular-nums',
                            'bg-primary dark:border-transparent dark:bg-status-critical-soft dark:text-status-critical text-white' => $counts['all'] > 0,
                            'bg-status-good-soft text-status-good' => $counts['all'] === 0,
                        ])>{{ $counts['all'] }}</span>
                    </div>
                    <p class="mt-0.5 text-xs text-secondary-medium dark:text-ink-muted">{{ __('dashboard.needs_attention_hint') }}</p>
                </div>

                @if ($counts['all'] > 0)
                    <div class="flex max-w-full gap-0.5 overflow-x-auto rounded-lg bg-board-head p-0.5 ring-1 ring-secondary-soft dark:ring-line" role="group" aria-label="{{ __('dashboard.filter_attention') }}">
                        @foreach ($focusTabs as $tabKey => $tabLabel)
                            <button
                                type="button"
                                x-on:click="focus = @js($tabKey)"
                                x-bind:aria-pressed="focus === @js($tabKey) ? 'true' : 'false'"
                                aria-pressed="{{ $tabKey === 'all' ? 'true' : 'false' }}"
                                wire:key="focus-{{ $tabKey }}"
                                class="inline-flex min-h-8 shrink-0 cursor-pointer items-center gap-1.5 rounded-md px-2.5 text-xs font-semibold whitespace-nowrap text-secondary-medium transition-[color,background-color,box-shadow] duration-150 hover:text-secondary-dark focus-visible:outline-2 focus-visible:outline-primary aria-pressed:bg-white aria-pressed:text-secondary-dark aria-pressed:shadow-[0_1px_2px_rgba(16,24,40,0.08)] dark:text-ink-muted dark:hover:text-ink dark:aria-pressed:bg-surface-2 dark:aria-pressed:text-ink"
                            >
                                {{ $tabLabel }}
                                <span class="tabular-nums opacity-70">{{ $counts[$tabKey] }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="flex-1">
                @if ($this->attentionItems->isEmpty())
                    <x-ui.empty-state icon="check-circle" :heading="__('dashboard.nothing_needs_attention')" :body="__('dashboard.nothing_needs_attention_body')" class="min-h-80" />
                @else
                    <ul class="divide-y divide-secondary-soft dark:divide-line" role="list">
                        @foreach ($this->attentionItems as $item)
                            @php
                                $inAll = $item['allRank'] !== null;
                                $inGroup = $item['groupRank'] < 8;
                            @endphp
                            <li
                                wire:key="attention-{{ $item['key'] }}"
                                data-attention-group="{{ $item['group'] }}"
                                x-show="focus === 'all' ? {{ $inAll ? 'true' : 'false' }} : (focus === @js($item['group']) && {{ $inGroup ? 'true' : 'false' }})"
                                x-transition:enter="transition-opacity duration-200 ease-out"
                                x-transition:enter-start="opacity-0"
                                @unless ($inAll) style="display: none" @endunless
                            >
                                <a
                                    href="{{ $item['href'] }}"
                                    wire:navigate
                                    class="group flex items-center gap-3 px-5 py-3 transition-colors duration-150 hover:bg-board-head focus-visible:bg-board-head focus-visible:outline-none"
                                >
                                    <span class="{{ $iconTone($item['tone']) }} flex size-9 shrink-0 items-center justify-center rounded-lg">
                                        <flux:icon :name="$item['icon']" variant="mini" class="size-4.5" />
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm font-semibold text-secondary-dark dark:text-ink">{{ $item['title'] }}</span>
                                        <span class="block truncate text-xs text-secondary-medium dark:text-ink-muted">{{ $item['context'] }}</span>
                                    </span>
                                    <span @class([
                                        'shrink-0 rounded-full px-2 py-0.5 text-[11px] font-bold tabular-nums whitespace-nowrap',
                                        'bg-status-critical-soft text-primary dark:bg-status-critical-soft dark:text-status-critical' => $item['daysLate'] === null || $item['daysLate'] > 14,
                                        'bg-white text-status-warn ring-1 ring-status-warn/40 ring-inset dark:bg-status-warn-soft dark:ring-0' => $item['daysLate'] !== null && $item['daysLate'] <= 14,
                                    ])>{{ $item['badge'] }}</span>
                                    <flux:icon.chevron-right variant="micro" class="size-4 shrink-0 text-secondary-light transition-transform duration-200 group-hover:translate-x-0.5 rtl:-scale-x-100 rtl:group-hover:-translate-x-0.5" />
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            @foreach ($hiddenCounts as $hiddenFocus => $hiddenCount)
                @if ($hiddenCount > 0)
                    <p
                        class="border-t border-secondary-soft px-5 py-2.5 text-xs text-secondary-medium dark:border-line dark:text-ink-muted"
                        x-show="focus === @js($hiddenFocus)"
                        @unless ($hiddenFocus === 'all') style="display: none" @endunless
                        wire:key="attention-hidden-{{ $hiddenFocus }}"
                    >
                        {{ trans_choice('dashboard.more_not_shown', $hiddenCount, ['count' => $hiddenCount]) }}
                    </p>
                @endif
            @endforeach
        </section>

        {{-- Funding position: agreements against transfers, only for someone who may open both --}}
        @if ($canViewFundingPosition)
        <section class="{{ $cardClasses }} flex min-w-0 flex-col @3xl/dashboard:col-span-3 @5xl/dashboard:col-span-5" aria-labelledby="funding-heading" data-funding-position>
            <div class="flex items-start justify-between gap-3 px-5 pt-4 pb-2">
                <div class="min-w-0">
                    <h2 id="funding-heading" class="text-base font-bold text-secondary-dark dark:text-ink">{{ __('dashboard.funding_position') }}</h2>
                    <p class="mt-0.5 text-xs text-secondary-medium dark:text-ink-muted">{{ __('dashboard.funding_position_hint') }}</p>
                </div>
                <a href="{{ route('funding-agreements.index') }}" wire:navigate class="shrink-0 rounded-md text-xs font-semibold text-secondary-medium underline-offset-4 hover:text-secondary-dark hover:underline focus-visible:outline-2 focus-visible:outline-primary dark:text-ink-muted dark:hover:text-ink">
                    {{ __('dashboard.view_agreements') }}
                </a>
            </div>

            @if ($this->fundingPosition->isEmpty())
                <x-ui.empty-state icon="banknotes" :heading="__('dashboard.no_funding_yet')" :body="__('dashboard.no_funding_yet_body')" class="min-h-48" />
            @else
                <ul class="flex flex-col divide-y divide-secondary-soft px-5 pb-2 dark:divide-line" role="list">
                    @foreach ($this->fundingPosition as $line)
                        <li class="py-3.5" wire:key="funding-{{ $line['currency'] }}">
                            <div class="flex items-baseline justify-between gap-3">
                                <div class="flex min-w-0 items-baseline gap-2">
                                    <span class="rounded-md bg-funding-soft px-1.5 py-0.5 text-[11px] font-bold tracking-wide text-funding">{{ $line['currency'] }}</span>
                                    <span class="truncate text-sm text-secondary-medium dark:text-ink-muted">
                                        <span class="font-bold text-secondary-dark tabular-nums dark:text-ink">{{ Number::format($line['received'], maxPrecision: 0) }}</span>
                                        {{ __('dashboard.of_committed', ['amount' => Number::format($line['committed'], maxPrecision: 0)]) }}
                                    </span>
                                </div>
                                <span class="text-xl font-bold tabular-nums text-funding dark:text-ink">{{ $line['share'] === null ? '—' : $line['share'].'%' }}</span>
                            </div>

                            <div
                                class="mt-2.5 h-2 w-full overflow-hidden rounded-full bg-secondary-soft dark:bg-surface-2"
                                role="progressbar"
                                aria-valuemin="0"
                                aria-valuemax="100"
                                aria-valuenow="{{ min(100, $line['share'] ?? 0) }}"
                                aria-label="{{ __('dashboard.received_share', ['currency' => $line['currency']]) }}"
                            >
                                <div class="h-full rounded-full bg-funding" style="width: {{ min(100, $line['share'] ?? 0) }}%"></div>
                            </div>

                            <p class="mt-2 flex flex-wrap items-center gap-x-1.5 text-xs text-secondary-medium dark:text-ink-muted">
                                @if ($line['committed'] <= 0)
                                    <span class="font-semibold text-status-warn dark:text-ink">{{ __('dashboard.no_commitment_recorded') }}</span>
                                @elseif ($line['pendingCount'] > 0)
                                    {{ trans_choice('dashboard.pending_transfers', $line['pendingCount'], ['count' => $line['pendingCount'], 'amount' => Number::format($line['pending'], maxPrecision: 0), 'currency' => $line['currency']]) }}
                                @else
                                    {{ __('dashboard.no_pending_transfers') }}
                                @endif
                            </p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
        @endif

        {{-- Portfolio pulse --}}
        <section @class([
            $cardClasses,
            'grid min-w-0 grid-cols-2 @5xl/dashboard:col-span-5',
            '@3xl/dashboard:col-span-3' => $canViewFundingPosition,
            '@3xl/dashboard:col-span-6' => ! $canViewFundingPosition,
        ]) aria-label="{{ __('dashboard.portfolio_pulse') }}" data-pulse>
            @foreach ($this->pulse as $figure)
                @php
                    // With an odd number of tiles the last one spans the full row.
                    $spansRow = $loop->last && $loop->count % 2 === 1;
                    $lastRowStart = $loop->count - ($loop->count % 2 === 0 ? 2 : 1);
                @endphp
                <a
                    href="{{ $figure['href'] }}"
                    wire:navigate
                    wire:key="pulse-{{ $figure['key'] }}"
                    @class([
                        'group flex flex-col justify-between gap-3 p-4 transition-colors duration-150 hover:bg-board-head focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary @5xl/dashboard:p-5',
                        'col-span-2' => $spansRow,
                        'border-e border-secondary-soft dark:border-line' => $loop->odd && ! $spansRow,
                        'border-b border-secondary-soft dark:border-line' => $loop->index < $lastRowStart,
                        'rounded-ss-xl' => $loop->index === 0,
                        'rounded-se-xl' => $loop->index === 1,
                        'rounded-es-xl' => $loop->index === $lastRowStart,
                        'rounded-ee-xl' => $loop->last,
                    ])
                >
                    <span class="text-xs font-medium leading-snug text-secondary-medium dark:text-ink-muted">{{ $figure['label'] }}</span>
                    <span class="flex items-baseline gap-1.5">
                        <span class="type-figure {{ $figureTone($figure['tone']) }}">{{ Number::format($figure['value']) }}</span>
                        @if ($figure['denominator'] !== null)
                            <span class="text-sm text-secondary-medium tabular-nums dark:text-ink-muted">/ {{ Number::format($figure['denominator']) }}</span>
                        @endif
                        <flux:icon.arrow-up-right variant="micro" class="ms-auto size-4 text-secondary-light opacity-0 transition-opacity duration-150 group-hover:opacity-100 rtl:-scale-x-100" />
                    </span>
                </a>
            @endforeach
        </section>
    </div>
