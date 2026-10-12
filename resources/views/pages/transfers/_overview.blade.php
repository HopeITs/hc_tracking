{{--
    The Overview view of the transfers page: the per-currency shortfall ledger
    and the ladder of tranches. Included by ⚡index.blade.php, which defines
    $mutedText, $inkText, $hairline and $sortOptions this markup reads.
    Figures are formatted by PresentsTransferRegister ($this->amount(),
    exact(), percent(), amountAtLeast(), rate()).
--}}
@include('pages.transfers._overview-ledger')

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

@include('pages.transfers._overview-ladder')
