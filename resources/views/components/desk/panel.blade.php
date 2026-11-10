@props([
    'id',
    'size',
    'cols' => 0,
    'height' => 0,
    'index',
    'total',
    'count' => null,
    'register' => null,
    'facets' => [],
    'filtered' => false,
    'filterable' => true,
    'search' => null,
    'searchValue' => '',
    'health' => null,
    'title' => null,
    'tagline' => null,
    'empty' => false,
])

@php
    /* A page card (App\Support\Desk\PageCards) brings its page's own title and line; a hand-made panel reads its own keys. */
    $title = $title ?? __("desk.panel.{$id}.title");
    $sizes = \App\Support\Desk\Panels::Sizes;
    $panels = app(\App\Support\Desk\Panels::class);
    /* The free cell grid: the entry's exact columns and row units, or the size's columns and the panel's own height for older layouts. */
    $span = (int) $cols > 0 ? max(\App\Support\Desk\Panels::MinCols, min(12, (int) $cols)) : $sizes[$size];
    $rows = (int) $height > 0 ? max(2, min(8, (int) $height)) : max(2, min(8, $panels->rowsOf($id)));
    $tagline = $tagline ?? __("desk-cards.tagline.{$id}");
    /* The panel state for its count colour and live dot: brand (decisions), critical, warn or good; none when neutral. */
    $health = in_array($health, ['brand', 'critical', 'warn', 'good'], true) ? $health : null;
    $filtered = $filtered || collect($facets)->contains(fn (array $choice): bool => $choice['value'] !== '');
    /* No funnel when the popup would only say there is nothing to filter. */
    $filterable = $filterable && ($facets !== [] || isset($filters));
    /* Nothing to show at all (not just a filter or a search that found nothing): on My desk it folds into a small tile (desk-fold.js). */
    $empty = $empty && ! $filtered && $searchValue === '';
    /* The icon a folded tile shows: the panel's own in the library, else a neutral one. */
    $foldIcon = auth()->check() ? (app(\App\Support\Desk\Panels::class)->availableTo(auth()->user())[$id]['icon'] ?? 'grid') : 'grid';
@endphp

{{--
    One Desk panel (a "sheet"): hover grip, eyebrow title, the hover tools
    (search, filter, open in full, options) and the count as a figure at the end,
    then optional tools, the body and an optional footer. The header drags the
    panel (resources/js/desk-grid.js); the menu's actions call the Desk page
    (pages::desk.index) through $wire.$parent.
--}}
<section {{ $attributes->class('dk-panel') }} id="dk-panel-{{ $id }}" @if ($health !== null) data-health="{{ $health }}" @endif style="--span:{{ $span }};--rows:{{ $rows }};--i:{{ $index }}" data-cols="{{ $span }}" data-rows="{{ $rows }}" data-panel="{{ $id }}" data-idx="{{ $index }}" @if ($empty) data-empty @endif aria-labelledby="dk-ph-{{ $id }}" x-data="deskPanel(@js($id))" @if ($search !== null) x-init="searching = @js($searchValue !== '')" @endif x-on:keydown.escape="menu = false">
    {{-- The whole header is the drag handle (desk-grid.js): press and move with a mouse, press and hold on touch. --}}
    <header class="dk-ph" data-drag="{{ $id }}" title="{{ __('desk.panel_drag') }}" x-on:click="if ($event.target.closest('[data-panel-menu]')) { return } if ($root.dataset.folded === 'user') { fold(false) } else if ($root.dataset.folded === 'empty') { peek() }">
        <span class="dk-fold-ic" aria-hidden="true"><x-desk.icon :name="$foldIcon" /></span>
        <span class="dk-grip" aria-hidden="true"><x-desk.icon name="grip" class="dk-grip-dots" /></span>
        <div class="dk-ph-t">
            <h2 id="dk-ph-{{ $id }}" title="{{ $title }}"><x-desk.icon name="pushpin" class="dk-pin-mark" x-cloak x-show="pinned()" />{{ $title }}</h2>
            @if (filled($tagline) && $tagline !== "desk-cards.tagline.{$id}")
                <p class="dk-tagline">{{ $tagline }}</p>
            @endif
        </div>
        {{-- The tools sit together: in the header's line on a wide card, over its end on a narrow one (desk-grid.css .dk-ph-tools). --}}
        <span class="dk-ph-tools" data-panel-tools-head>
        @if ($search !== null)
        <button type="button" @class(['dk-iconbtn dk-sm dk-psearch-btn', 'dk-on' => $searchValue !== '']) x-on:click="searching = ! searching; if (searching) { $nextTick(() => $refs.search?.focus()) }" x-bind:aria-expanded="searching.toString()" aria-label="{{ __('desk.panel_search', ['panel' => $title]) }}" title="{{ __('desk.search') }}" data-panel-search-toggle>
            <x-desk.icon name="search" />
        </button>
        @endif
        @if ($filterable)
        <button type="button" @class(['dk-iconbtn dk-sm dk-pfilter', 'dk-on' => $filtered]) x-on:click="openFilters()" aria-haspopup="dialog" aria-label="{{ __('desk.panel_filter', ['panel' => $title]) }}" title="{{ __('desk.filter') }}" data-panel-filter>
            <flux:icon.funnel variant="outline" class="size-4" />
        </button>
        @endif
        @if ($register !== null)
            <a href="{{ route($register) }}" wire:navigate class="dk-iconbtn dk-sm" data-panel-register aria-label="{{ __('desk.panel_open_full', ['panel' => $title]) }}" title="{{ __('desk.panel_open_full_short') }}">
                <x-desk.icon name="expand" />
            </a>
        @endif
        <span class="dk-pmenu" x-on:click.outside="menu = false" data-panel-menu>
            <button type="button" class="dk-iconbtn dk-sm" x-on:click="menu = ! menu" x-bind:aria-expanded="menu" aria-label="{{ __('desk.panel_options') }}">
                <x-desk.icon name="more" />
            </button>
            {{-- On My desk: pin to top and collapse (pages::desk.index); everywhere: remove. --}}
            <div class="dk-pop dk-pmenu-pop" role="menu" aria-label="{{ __('desk.panel_options_for', ['panel' => $title]) }}" x-show="menu" x-cloak x-transition.opacity.duration.100ms>
                <p class="dk-pop-h">{{ $title }}</p>
                <template x-if="onDesk()">
                    <div class="dk-pmenu-group">
                        <button type="button" role="menuitem" x-on:click="menu = false; pin(! pinned())" data-panel-pin>
                            <x-desk.icon name="pushpin" />
                            <span x-text="pinned() ? @js(__('desk.unpin_panel')) : @js(__('desk.pin_panel'))"></span>
                        </button>
                        <button type="button" role="menuitem" x-show="! foldsItself()" x-on:click="menu = false; fold(! folded())" data-panel-fold>
                            <x-desk.icon name="fold" x-show="! folded()" />
                            <x-desk.icon name="unfold" x-show="folded()" x-cloak />
                            <span x-text="folded() ? @js(__('desk.expand_panel')) : @js(__('desk.collapse_panel'))"></span>
                        </button>
                    </div>
                </template>
                <button type="button" role="menuitem" class="dk-danger" x-on:click="menu = false; $dispatch('dk-panel-removed', { panel: '{{ $id }}', title: @js($title), position: {{ $index }}, size: '{{ $size }}', cols: {{ $span }}, rows: {{ $rows }} }); $wire.$parent.removePanel('{{ $id }}')"><x-desk.icon name="x" />{{ __('desk.remove_panel') }}</button>
            </div>
        </span>
        </span>
        @if ($count !== null && $count !== '')
            <span class="dk-pc dk-num" data-panel-count>{{ $count }}</span>
        @endif
        {{-- Folded into a tile (desk-fold.js): an empty panel says so; one the person folded opens again from here. --}}
        <p class="dk-fold-note" aria-hidden="true">{{ __('desk.panel_nothing_to_show') }}</p>
        <button type="button" class="dk-unfold" x-on:click.stop="fold(false)" x-on:pointerdown.stop aria-label="{{ __('desk.expand_panel_named', ['panel' => $title]) }}" title="{{ __('desk.expand_panel') }}" data-panel-unfold>
            <x-desk.icon name="expand" />
        </button>
    </header>

    @if ($search !== null)
        {{-- Search narrows the list as you type (it is a search, not a filter, so it needs no Done). --}}
        <div class="dk-psearch" x-show="searching" x-cloak data-panel-search>
            <x-desk.icon name="search" />
            <input type="search" x-ref="search" wire:model.live.debounce.300ms="{{ $search }}" placeholder="{{ __('desk.search_placeholder', ['panel' => $title]) }}" aria-label="{{ __('desk.panel_search', ['panel' => $title]) }}" x-on:keydown.escape.stop="if ($el.value === '') { searching = false } else { $el.value = ''; $wire.$set('{{ $search }}', '') }">
        </div>
    @endif

    @isset($tools)
        <div class="dk-ptools" data-panel-tools>{{ $tools }}</div>
    @endisset

    <div class="dk-pb">{{ $slot }}</div>

    @isset($foot)
        <footer class="dk-pf" data-panel-foot>{{ $foot }}</footer>
    @endisset

    {{-- Free cell resize (desk-grid.js), the only way to size a card: pull the side edges for width, top or bottom for height, the corner for both. --}}
    @foreach (['end', 'start', 'top', 'bottom', 'corner'] as $edge)
        <span class="dk-rz dk-rz-{{ $edge }}" data-resize="{{ $edge }}" title="{{ __('desk.panel_resize') }}" aria-hidden="true"></span>
    @endforeach

    {{-- The panel's filters as a small centered popup of dropdowns: choices read from its rows, plus the panel's own (filters slot). They are a draft until Done (resources/js/desk-filters.js). --}}
    @if ($filterable)
    <template x-teleport="body">
        <div x-show="filtering" x-cloak class="dk-scope" x-on:keydown.escape.window.stop="discardFilters()">
            <div class="dk-sh-scrim dk-pf-scrim" x-on:click="discardFilters()"></div>
            <div class="dk-sheet dk-pf-pop" role="dialog" aria-modal="true" aria-labelledby="dk-pf-t-{{ $id }}" data-panel-filter-dialog="{{ $id }}">
                <div class="dk-sh-h">
                    <div class="min-w-0">
                        <h2 id="dk-pf-t-{{ $id }}">{{ __('desk.filter_panel', ['panel' => $title]) }}</h2>
                        <p>{{ __('desk.filter_lede') }}</p>
                    </div>
                    <button type="button" class="dk-iconbtn" x-on:click="discardFilters()" aria-label="{{ __('common.close') }}"><flux:icon.x-mark variant="outline" class="size-5" /></button>
                </div>
                <div class="dk-sh-b dk-pf-body">
                    <div class="dk-pf-grid directory-filters">
                        @foreach ($facets as $facet => $choice)
                            <x-ui.header-filter-select :label="$choice['label']" wire:model="facets.{{ $facet }}" :value="$choice['value']" deferred wire:key="pf-{{ $id }}-{{ $facet }}">
                                <option value="">{{ __('desk.filter_any', ['facet' => $choice['label']]) }}</option>
                                @foreach ($choice['options'] as $option)
                                    <option value="{{ $option }}">{{ $option }}</option>
                                @endforeach
                            </x-ui.header-filter-select>
                        @endforeach
                    </div>
                    @isset($filters)
                        <div class="dk-pf-grid">{{ $filters }}</div>
                    @endisset
                </div>
                <div class="dk-sh-f">
                    <button type="button" class="dk-btn dk-ghost" x-on:click="clearDraft()">{{ __('desk.filter_clear') }}</button>
                    <button type="button" class="dk-btn dk-primary" x-on:click="applyFilters()">{{ __('desk.filter_done') }}</button>
                </div>
            </div>
        </div>
    </template>
    @endif
</section>
