<?php

use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Support\Desk\Cards\PageCard;
use App\Support\Desk\Destinations;
use App\Support\Desk\PageCards;
use App\Support\Desk\Shortcuts;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A page in card form on the Desk ("p-…" items, App\Support\Desk\PageCards):
 * the page's headline figures, the split of its records by state, and its
 * records as rows that open in a Desk tab. One component draws every page's
 * card; App\Support\Desk\Cards says what each page holds. How much of each
 * part shows follows the card's own size (desk-workspace.css, .dk-card), so a
 * card reads well from three columns by two rows up to the full width.
 */
new class extends Component {
    use IsDeskPanel;

    /**
     * The card's Desk item id ("p-admin-evidence-types").
     */
    #[Locked]
    public string $card = '';

    public string $search = '';

    public function updatedSearch(): void
    {
        $this->page = 0;
    }

    #[Computed]
    public function source(): ?PageCard
    {
        return app(PageCards::class)->forUser(auth()->user(), $this->card);
    }

    /**
     * @return list<array{label: string, value: string, tone: string}>
     */
    #[Computed]
    public function figures(): array
    {
        return $this->source?->figures(auth()->user()) ?? [];
    }

    /**
     * The card's own picture of its page (PageCard::hero), null for the plain figures.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function hero(): ?array
    {
        return $this->source?->hero(auth()->user());
    }

    /**
     * @return list<array{label: string, count: int, tone: string}>
     */
    #[Computed]
    public function breakdown(): array
    {
        return $this->source?->breakdown(auth()->user()) ?? [];
    }

    /**
     * @return Collection<int, array{key: string, title: string, meta: string, badge: array{label: string, tone: string}|null, href: string, words: string, lead: array{kind: string, big: string, small: string, tone: string}|null, end: array{big: string, small: string}|null, bar: array{from: float, to: float, now: float|null, tone: string}|null}>
     */
    #[Computed]
    public function rows(): Collection
    {
        return $this->source?->rows(auth()->user(), $this->search) ?? new Collection;
    }

    /**
     * A row filters by its state (the badge), the only facet every card shares.
     *
     * @return array<string, string>
     */
    protected function facetsOf(mixed $row): array
    {
        return is_array($row) && is_array($row['badge'] ?? null) ? ['status' => $row['badge']['label']] : [];
    }
};
?>

@php
    $source = $this->source;
    $route = $source?->route();
    $page = $route !== null ? app(Destinations::class)->find($route) : null;
    $figures = $this->figures;
    $parts = $this->breakdown;
    $whole = array_sum(array_column($parts, 'count'));
    $rows = $this->rows;
    $layout = $source?->layout() ?? 'list';
    $hero = $this->hero;
    $pg = $this->paginate($rows, self::PerPage);
    $create = $route !== null ? app(Shortcuts::class)->actionForRoute(auth()->user(), $route) : null;
    $alarm = collect($figures)->contains(fn (array $figure): bool => $figure['tone'] === 'critical' && ! in_array($figure['value'], ['0', '٠'], true));
    $about = $route !== null && \Illuminate\Support\Facades\Lang::has('desk-workspace.card.tagline.'.Destinations::slugOf($route)) ? __('desk-workspace.card.tagline.'.Destinations::slugOf($route)) : null;
    $tones = ['good' => 'dk-good', 'warn' => 'dk-warn', 'critical' => 'dk-bad', 'active' => 'dk-act', 'neutral' => 'dk-mute'];
@endphp

<x-desk.panel :empty="$source === null || $rows->isEmpty()"
    :id="$card"
    :title="$page['label'] ?? __('desk-workspace.card.unavailable')"
    :tagline="$about"
    :facets="$pg['facets']"
    :register="$route"
    :size="$size"
    :cols="$gridCols"
    :height="$gridRows"
    :index="$index"
    :total="$total"
    :health="$alarm ? 'critical' : null"
    search="search"
    :search-value="$search"
    class="dk-pagecard"
    data-card="{{ $route }}"
>
    @if ($source === null)
        <p class="dk-pempty">{{ __('desk-workspace.card.unavailable') }}</p>
    @else
        <div class="dk-card" data-card-body data-card-layout="{{ $layout }}">
            @if ($hero !== null)
                <x-desk.card-hero :hero="$hero" />
            @endif

            @if ($figures !== [])
                {{-- With a picture of its own, the card's figures stay for screen readers only. --}}
                <dl @class(['dk-card-figs', 'sr-only' => $hero !== null]) data-card-figures>
                    @foreach ($figures as $figure)
                        <div class="dk-card-fig" data-tone="{{ $figure['tone'] }}">
                            <dt>{{ $figure['label'] }}</dt>
                            <dd class="dk-num">{{ $figure['value'] }}</dd>
                        </div>
                    @endforeach
                </dl>
            @endif

            @if ($hero === null && $whole > 0 && count($parts) > 1)
                {{-- The whole split by state: one bar, its parts in proportion, then a legend that wraps. --}}
                <div class="dk-card-split" data-card-split>
                    <div class="dk-card-bar" role="img" aria-label="{{ collect($parts)->map(fn (array $part): string => $part['label'].' '.$part['count'])->implode(', ') }}">
                        @foreach ($parts as $part)
                            <span data-tone="{{ $part['tone'] }}" style="flex-grow: {{ $part['count'] }}" title="{{ $part['label'] }} · {{ $part['count'] }}"></span>
                        @endforeach
                    </div>
                    <ul class="dk-card-legend" aria-hidden="true">
                        @foreach ($parts as $part)
                            <li><i data-tone="{{ $part['tone'] }}"></i><span>{{ $part['label'] }}</span><b class="dk-num">{{ $part['count'] }}</b></li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($pg['total'] > 0)
                {{-- The records take the shape of what they are (PageCard::layout): a date block, a year span, a meter, initials, an amount… --}}
                <ul class="dk-card-rows" {{ $this->fitAttributes($pg) }} data-card-rows data-layout="{{ $layout }}">
                    @foreach ($pg['slice'] as $row)
                        <li wire:key="{{ $card }}-{{ $row['key'] }}">
                            <a href="{{ $row['href'] }}" wire:navigate class="dk-card-row" title="{{ $row['title'] }}" data-tone="{{ $row['badge']['tone'] ?? 'neutral' }}">
                                @if ($row['lead'] !== null)
                                    <span class="dk-card-l" data-kind="{{ $row['lead']['kind'] }}" data-tone="{{ $row['lead']['tone'] }}" aria-hidden="true">
                                        @if ($row['lead']['big'] !== '')<b>{{ $row['lead']['big'] }}</b>@endif
                                        @if ($row['lead']['small'] !== '')<small>{{ $row['lead']['small'] }}</small>@endif
                                    </span>
                                @endif
                                <span class="dk-card-t">{{ $row['title'] }}</span>
                                @if ($row['meta'] !== '')
                                    <span class="dk-card-m">{{ $row['meta'] }}</span>
                                @endif
                                @if ($row['bar'] !== null)
                                    <span class="dk-card-r" data-tone="{{ $row['bar']['tone'] }}" style="--from: {{ $row['bar']['from'] }}%; --to: {{ $row['bar']['to'] }}%;@if ($row['bar']['now'] !== null) --now: {{ $row['bar']['now'] }}%;@endif" aria-hidden="true">@if ($row['bar']['to'] > $row['bar']['from'])<i></i>@endif @if ($row['bar']['now'] !== null)<em></em>@endif</span>
                                @endif
                                @if ($row['end'] !== null)
                                    <span class="dk-card-e"><b class="dk-num">{{ $row['end']['big'] }}</b>@if ($row['end']['small'] !== '')<small>{{ $row['end']['small'] }}</small>@endif</span>
                                @endif
                                @if ($row['badge'] !== null)
                                    <span class="dk-pill {{ $tones[$row['badge']['tone']] ?? 'dk-mute' }} dk-card-b">{{ $row['badge']['label'] }}</span>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            @else
                <div class="dk-card-empty" data-card-empty>
                    <p>{{ $search !== '' || $this->facets !== [] ? __('desk-workspace.card.no_match') : __('desk-workspace.card.empty', ['page' => $page['label'] ?? '']) }}</p>
                </div>
            @endif
        </div>
    @endif

    <x-slot:foot>
        <span class="dk-card-pager"><x-desk.pager :pg="$pg" /></span>
        @if ($create !== null)
            <a href="{{ $create['href'] }}" wire:navigate class="dk-btn dk-sm dk-card-new" title="{{ $create['label'] }}" aria-label="{{ $create['label'] }}" data-card-create><x-desk.icon name="plus" /><span>{{ $create['label'] }}</span></a>
        @endif
    </x-slot:foot>
</x-desk.panel>
