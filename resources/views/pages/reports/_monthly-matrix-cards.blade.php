{{--
    The Monthly Matrix rows in cards (ReadsMatrixProgress::matrixCards): one
    card per proposal or per center, folded shut by default. Each card heads
    its rows with the window's months, so the month boxes below line up.
    Included by ⚡monthly-matrix.blade.php, whose top PHP block defines
    $windowMonths, $paged and $monthFormat.
--}}
<div class="mm-cards" data-matrix-cards>
    @forelse ($this->matrixCards as $card)
        <section class="mm-card" wire:key="card-{{ $card['key'] }}" x-data="{ open: false }" data-matrix-card>
            <button type="button" class="mm-card-head" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" aria-expanded="false" aria-label="{{ __('reports.matrix.toggle_card', ['name' => $card['title']]) }}">
                <span class="mm-card-title">
                    <b dir="auto">{{ $card['title'] }}</b>
                    @if ($card['sub'] !== null)
                        <span dir="auto">{{ $card['sub'] }}</span>
                    @endif
                </span>
                <span class="mm-card-badges">
                    <span class="mm-badge">{{ trans_choice('reports.matrix.card_rows', count($card['rows']), ['count' => count($card['rows'])]) }}</span>
                    @if ($card['behind'] > 0)
                        <span class="mm-badge mm-badge-line">{{ __('reports.matrix.card_behind', ['count' => $card['behind']]) }}</span>
                    @endif
                    @if ($card['missing'] > 0)
                        <span class="mm-badge mm-badge-red">{{ trans_choice('reports.matrix.card_missing', $card['missing'], ['count' => $card['missing']]) }}</span>
                    @endif
                </span>
                <flux:icon.chevron-down class="mm-chev size-5" x-bind:class="open ? 'rotate-180' : ''" aria-hidden="true" />
            </button>

            <div x-show="open" x-cloak>
                <div class="mm-line mm-line-head" aria-hidden="true">
                    <span class="mm-ind">{{ __('reports.matrix.col_indicator') }}</span>
                    <span class="mm-prog">{{ __('reports.matrix.col_progress') }}</span>
                    <span class="mm-strip">
                        <span class="mm-months" style="--mm-cols: {{ count($windowMonths) }}">
                            @foreach ($windowMonths as $month)
                                <span class="mm-month" data-matrix-month="{{ $month->format('Y-m') }}" title="{{ $month->translatedFormat($monthFormat) }}">
                                    <span class="sr-only">{{ $month->translatedFormat($monthFormat) }}</span>
                                    <b>{{ $month->translatedFormat('M') }}</b>
                                    @if ($loop->first || $month->month === 1)
                                        <small dir="ltr">{{ $month->year }}</small>
                                    @endif
                                </span>
                            @endforeach
                        </span>
                    </span>
                    <span class="mm-fold-btn-space"></span>
                </div>

                @foreach ($card['rows'] as $row)
                    @include('pages.reports._monthly-matrix-row')
                @endforeach
            </div>
        </section>
    @empty
        <div class="mm-none" data-matrix-none>
            <b>{{ __('reports.matrix.no_matching_indicators') }}</b>
            <button type="button" class="dk-btn dk-sm" wire:click="clearFilters" data-test="matrix-none-reset">{{ __('reports.matrix.reset_filters') }}</button>
        </div>
    @endforelse

    @if ($paged->hasPages())
        <div class="mm-pages">
            <flux:pagination :paginator="$paged" scroll-to="#monthly-matrix-heading" />
        </div>
    @endif
</div>
