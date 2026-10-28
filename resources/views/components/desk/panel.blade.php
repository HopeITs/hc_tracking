@props([
    'id',
    'size',
    'index',
    'total',
    'count' => null,
    'register' => null,
    'facets' => [],
    'filtered' => false,
    'filterable' => true,
    'search' => null,
    'searchValue' => '',
])

@php
    $title = __("desk.panel.{$id}.title");
    $sizes = \App\Support\Desk\Panels::Sizes;
    $filtered = $filtered || collect($facets)->contains(fn (array $choice): bool => $choice['value'] !== '');
    /* No funnel when the popup would only say there is nothing to filter. */
    $filterable = $filterable && ($facets !== [] || isset($filters));
@endphp

{{--
    One Desk panel: grip, title, count, "open in full" and the options menu,
    then optional tools, the body and an optional footer. The grip drags the
    panel (resources/js/desk.js); the menu's actions call the Desk page
    (pages::desk.index) through $wire.$parent.
--}}
<section {{ $attributes->class('dk-panel') }} style="--span:{{ $sizes[$size] }}" data-panel="{{ $id }}" data-idx="{{ $index }}" aria-labelledby="dk-ph-{{ $id }}" x-data="deskPanel(@js($id))" @if ($search !== null) x-init="searching = @js($searchValue !== '')" @endif x-on:keydown.escape="menu = false">
    <header class="dk-ph">
        <button type="button" class="dk-grip" draggable="true" data-drag="{{ $id }}" aria-label="{{ __('desk.panel_move', ['panel' => $title]) }}" title="{{ __('desk.panel_drag') }}">
            <x-desk.icon name="grip" />
        </button>
        <h2 id="dk-ph-{{ $id }}">{{ $title }}</h2>
        @if ($count !== null && $count !== '')
            <span class="dk-pc dk-num" data-panel-count>{{ $count }}</span>
        @endif
        <span class="dk-pspace"></span>
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
            <div class="dk-pop" role="menu" x-show="menu" x-cloak>
                <p class="dk-pop-h">{{ __('desk.size') }}</p>
                <div class="dk-sizes">
                    @foreach (array_keys($sizes) as $option)
                        <button type="button" role="menuitemradio" aria-checked="{{ $option === $size ? 'true' : 'false' }}" title="{{ __("desk.size_{$option}") }}" x-on:click="menu = false; $wire.$parent.resizePanel('{{ $id }}', '{{ $option }}')">{{ $option }}</button>
                    @endforeach
                </div>
                @if ($index > 0)
                    <button type="button" role="menuitem" x-on:click="menu = false; $wire.$parent.movePanel('{{ $id }}', {{ $index - 1 }})"><x-desk.icon name="back" class="dk-flip" />{{ __('desk.move_earlier') }}</button>
                @endif
                @if ($index < $total - 1)
                    <button type="button" role="menuitem" x-on:click="menu = false; $wire.$parent.movePanel('{{ $id }}', {{ $index + 1 }})"><x-desk.icon name="chev" class="dk-flip" />{{ __('desk.move_later') }}</button>
                @endif
                <button type="button" role="menuitem" class="dk-danger" x-on:click="menu = false; $wire.$parent.removePanel('{{ $id }}')"><x-desk.icon name="x" />{{ __('desk.remove_panel') }}</button>
            </div>
        </span>
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
