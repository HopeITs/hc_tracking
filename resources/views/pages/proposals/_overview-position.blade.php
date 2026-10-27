        {{-- Where the money stands: one line per currency, never summed across them. --}}
        @if ($canAgreements)
        <section aria-labelledby="money-heading" class="pt-3" data-funding-position>
            <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1">
                <h2 id="money-heading" class="text-lg font-bold {{ $inkText }}">{{ __('proposals.where_the_money_stands') }}</h2>
                <p class="text-xs {{ $mutedText }}">{{ __('proposals.budgets_are_shown_per_currency_they_are_never_added_together') }}</p>
            </div>

            @forelse ($this->fundingPosition as $line)
                @php
                    $barBase = max($line['committed'], $line['received'] + $line['pending'], 1);
                    $figures = [
                        ['key' => 'committed', 'label' => __('proposals.committed'), 'value' => $line['committed'], 'tone' => $inkText, 'note' => trans_choice('proposals.across_count_proposals', $matchingCount, ['count' => $matchingCount])],
                        ['key' => 'received', 'label' => __('proposals.received'), 'value' => $line['received'], 'tone' => $receivedText, 'note' => __('proposals.percent_of_committed', ['percent' => App\Models\DonorProposal::percentLabel($line['received'], $line['committed'])])],
                        ['key' => 'pending', 'label' => __('proposals.pending'), 'value' => $line['pending'], 'tone' => 'text-status-warn', 'note' => trans_choice('proposals.count_transfers_scheduled', $line['pendingCount'], ['count' => $line['pendingCount']])],
                        ['key' => 'unscheduled', 'label' => __('proposals.not_yet_scheduled'), 'value' => $line['unscheduled'], 'tone' => $mutedText, 'note' => __('proposals.committed_with_no_transfer_yet')],
                    ];
                @endphp
                <div class="mt-5 border-t pt-5 {{ $hairline }}" wire:key="money-{{ $line['currency'] }}">
                    <div class="grid grid-cols-2 gap-x-6 gap-y-6 @4xl/directory:grid-cols-[3.5rem_repeat(4,minmax(0,1fr))]">
                        <p class="col-span-2 text-sm font-extrabold tracking-wide @4xl/directory:col-span-1 @4xl/directory:pt-0.5 {{ $receivedText }}">{{ $line['currency'] }}</p>
                        @foreach ($figures as $figure)
                            <div class="logframe-reveal min-w-0" style="--logframe-order: {{ $loop->index }}" data-figure="{{ $figure['key'] }}">
                                <p class="text-xs font-semibold {{ $mutedText }}">{{ $figure['label'] }}</p>
                                <p
                                    class="mt-1.5 truncate text-3xl leading-none font-extrabold tracking-tight tabular-nums @6xl/directory:text-[2.625rem] {{ $figure['tone'] }}"
                                    x-data="countUp({{ (int) round($figure['value']) }})"
                                    x-text="new Intl.NumberFormat('en-US').format(display)"
                                >{{ $amount($figure['value']) }}</p>
                                <p class="mt-2 text-xs {{ $mutedText }}">{{ $figure['note'] }}</p>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-6 flex h-3 w-full gap-0.5 overflow-hidden rounded-full bg-secondary-soft dark:bg-surface-2 @4xl/directory:ms-[calc(3.5rem+1.5rem)] @4xl/directory:w-[calc(100%-5rem)]" role="img" aria-label="{{ __('proposals.money_bar_label', ['currency' => $line['currency'], 'received' => $amount($line['received']), 'pending' => $amount($line['pending']), 'committed' => $amount($line['committed'])]) }}">
                        <span class="money-grow h-full rounded-s-full bg-funding" style="width: {{ round($line['received'] / $barBase * 100, 2) }}%"></span>
                        <span class="money-grow money-pending h-full" style="width: {{ round($line['pending'] / $barBase * 100, 2) }}%"></span>
                    </div>
                </div>
            @empty
                <p class="mt-4 border-t pt-4 text-sm {{ $hairline }} {{ $mutedText }}">{{ __('proposals.no_money_committed_to_these_proposals_yet') }}</p>
            @endforelse
        </section>
        @endif

        {{-- The pipeline rail: five phases on one thread; a stop filters the page. --}}
        <nav aria-label="{{ __('proposals.pipeline_phases') }}" @class(['pt-6', 'border-t '.$hairline => $canAgreements]) data-pipeline>
            <ol class="relative grid grid-cols-5 gap-1">
                <span class="absolute inset-x-[10%] top-[0.4375rem] h-px bg-secondary-soft dark:bg-line-strong" aria-hidden="true"></span>
                @foreach ($this->pipeline as $stop)
                    @php
                        $isSelected = $phase === $stop['phase']->value;
                        $isEmpty = $stop['count'] === 0;
                    @endphp
                    <li class="relative min-w-0" wire:key="phase-{{ $stop['phase']->value }}">
                        <button
                            type="button"
                            wire:click="togglePhase('{{ $stop['phase']->value }}')"
                            aria-pressed="{{ $isSelected ? 'true' : 'false' }}"
                            class="group flex w-full flex-col items-center gap-1.5 rounded-lg px-1 pb-2 text-center transition-colors duration-150 hover:bg-board-head focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium dark:hover:bg-white/5"
                            data-phase-stop="{{ $stop['phase']->value }}"
                        >
                            <span @class([
                                'relative z-10 size-3.5 rounded-full border-2 transition-transform duration-200 ease-out group-hover:scale-125',
                                'border-primary bg-primary dark:bg-brand dark:border-brand ring-4 ring-secondary-light' => $isSelected,
                                'border-secondary-dark bg-secondary-dark dark:border-ink-muted dark:bg-ink-muted' => ! $isSelected && ! $isEmpty,
                                'border-secondary-light bg-white dark:bg-surface-1' => ! $isSelected && $isEmpty,
                            ]) aria-hidden="true"></span>
                            <span title="{{ $stop['phase']->label() }}" @class(['mt-1 w-full truncate text-[11px] font-bold @xl/directory:text-xs @3xl/directory:text-sm', 'text-primary dark:text-brand-text' => $isSelected, $inkText => ! $isSelected && ! $isEmpty, $mutedText => ! $isSelected && $isEmpty])>{{ $stop['phase']->label() }}</span>
                            <span @class(['text-2xl leading-none font-extrabold tabular-nums @3xl/directory:text-3xl', 'text-secondary-light' => $isEmpty, $inkText => ! $isEmpty])>{{ $stop['count'] }}</span>
                            <span class="flex min-h-4 flex-col items-center gap-0.5 text-[11px] font-medium tabular-nums {{ $mutedText }}">
                                @foreach ($stop['money'] as $currency => $total)
                                    <span>{{ $currency }} {{ $shortAmount($total) }}</span>
                                @endforeach
                                @if ($stop['rejected'] > 0)
                                    <span class="font-semibold text-primary dark:text-brand-text">{{ trans_choice('proposals.count_rejected', $stop['rejected'], ['count' => $stop['rejected']]) }}</span>
                                @endif
                            </span>
                        </button>
                    </li>
                @endforeach
            </ol>
        </nav>
