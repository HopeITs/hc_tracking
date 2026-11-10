@props([
    'entry',
    'button',
    'index',
    'total',
])

@php
    $id = $entry['panel'];
    $span = max(\App\Support\Desk\Panels::MinCols, min(12, (int) $entry['cols']));
    $rows = max(\App\Support\Desk\Panels::MinTileRows, min(\App\Support\Desk\Panels::MaxRows, (int) $entry['rows']));
    $isAction = $button['kind'] === 'action';
    $page = app(\App\Support\Desk\Destinations::class)->find($button['route']);
    $hint = match (true) {
        $isAction && $page !== null => __('desk-workspace.tile.opens_form', ['page' => $page['label']]),
        $isAction => __('desk-workspace.ask.opens_form'),
        default => __('desk-workspace.tile.opens_page'),
    };
@endphp

{{--
    A button on the Desk ("a-…" a "New …" action, "g-…" a page; App\Support\Desk\Shortcuts).
    It is a Desk cell like any panel: dragged by its face (desk-grid.js lets a press that moves
    carry it, and a press that does not open it), pulled from its edges, and laid out by the
    same flush rows. It may be one row unit tall; its face reflows to its cell (desk-cards.css
    .dk-tile container queries), so a strip of buttons and a large square both read well.
--}}
<section {{ $attributes->class(['dk-panel', 'dk-tile', 'dk-tile-action' => $isAction]) }} id="dk-panel-{{ $id }}" style="--span:{{ $span }};--rows:{{ $rows }};--i:{{ $index }}" data-cols="{{ $span }}" data-rows="{{ $rows }}" data-min-rows="{{ \App\Support\Desk\Panels::MinTileRows }}" data-panel="{{ $id }}" data-idx="{{ $index }}" data-tile="{{ $button['kind'] }}" x-data="deskPanel(@js($id))" x-on:keydown.escape="menu = false">
    <a href="{{ $button['href'] }}" wire:navigate class="dk-tile-a" data-drag="{{ $id }}" data-drag-through title="{{ $button['label'] }}" data-tile-open>
        <span class="dk-tile-ic" aria-hidden="true">
            <flux:icon :name="$button['icon']" variant="outline" />
            @if ($isAction)
                <span class="dk-tile-plus"><x-desk.icon name="plus" /></span>
            @endif
        </span>
        <span class="dk-tile-t">
            <strong>{{ $button['label'] }}</strong>
            <small>{{ $hint }}</small>
        </span>
        <span class="dk-tile-go" aria-hidden="true"><x-desk.icon name="arrow" class="dk-flip" /></span>
    </a>

    <x-desk.icon name="pushpin" class="dk-pin-mark dk-tile-pin" x-cloak x-show="pinned()" />

    <span class="dk-pmenu dk-tile-menu" x-on:click.outside="menu = false" data-panel-menu>
        <button type="button" class="dk-iconbtn dk-sm" x-on:click="menu = ! menu" x-bind:aria-expanded="menu" aria-label="{{ __('desk-workspace.tile.options', ['button' => $button['label']]) }}">
            <x-desk.icon name="more" />
        </button>
        <div class="dk-pop dk-pmenu-pop" role="menu" aria-label="{{ __('desk-workspace.tile.options', ['button' => $button['label']]) }}" x-show="menu" x-cloak x-transition.opacity.duration.100ms>
            <p class="dk-pop-h">{{ $button['label'] }}</p>
            <button type="button" role="menuitem" x-on:click="menu = false; pin(! pinned())" data-panel-pin>
                <x-desk.icon name="pushpin" />
                <span x-text="pinned() ? @js(__('desk.unpin_panel')) : @js(__('desk.pin_panel'))"></span>
            </button>
            <button type="button" role="menuitem" class="dk-danger" x-on:click="menu = false; $dispatch('dk-panel-removed', { panel: '{{ $id }}', title: @js($button['label']), position: {{ $index }}, size: '{{ $entry['size'] }}', cols: {{ $span }}, rows: {{ $rows }} }); $wire.removePanel('{{ $id }}')"><x-desk.icon name="x" />{{ __('desk-workspace.tile.remove') }}</button>
        </div>
    </span>

    @foreach (['end', 'start', 'top', 'bottom', 'corner'] as $edge)
        <span class="dk-rz dk-rz-{{ $edge }}" data-resize="{{ $edge }}" title="{{ __('desk.panel_resize') }}" aria-hidden="true"></span>
    @endforeach
</section>
