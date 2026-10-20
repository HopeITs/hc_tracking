@props([
    'id',
    'size',
    'index',
    'total',
    'count' => null,
    'register' => null,
])

@php
    $title = __("desk.panel.{$id}.title");
    $sizes = \App\Support\Desk\Panels::Sizes;
@endphp

{{--
    One Desk panel: grip, title, count, "open in full" and the options menu,
    then optional tools, the body and an optional footer. The grip drags the
    panel (resources/js/desk.js); the menu's actions call the Desk page
    (pages::desk.index) through $wire.$parent.
--}}
<section {{ $attributes->class('dk-panel') }} style="--span:{{ $sizes[$size] }}" data-panel="{{ $id }}" data-idx="{{ $index }}" aria-labelledby="dk-ph-{{ $id }}" x-data="{ menu: false }" x-on:keydown.escape="menu = false">
    <header class="dk-ph">
        <button type="button" class="dk-grip" draggable="true" data-drag="{{ $id }}" aria-label="{{ __('desk.panel_move', ['panel' => $title]) }}" title="{{ __('desk.panel_drag') }}">
            <x-desk.icon name="grip" />
        </button>
        <h2 id="dk-ph-{{ $id }}">{{ $title }}</h2>
        @if ($count !== null && $count !== '')
            <span class="dk-pc dk-num">{{ $count }}</span>
        @endif
        <span class="dk-pspace"></span>
        @if ($register !== null)
            <a href="{{ route($register) }}" wire:navigate class="dk-iconbtn dk-sm" aria-label="{{ __('desk.panel_open_full', ['panel' => $title]) }}" title="{{ __('desk.panel_open_full_short') }}">
                <x-desk.icon name="expand" />
            </a>
        @endif
        <span class="dk-pmenu" x-on:click.outside="menu = false">
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
                <button type="button" role="menuitem" class="dk-danger" x-on:click="menu = false; $wire.$parent.removePanel('{{ $id }}')"><x-desk.icon name="x" />{{ __('desk.remove_from_desk') }}</button>
            </div>
        </span>
    </header>

    @isset($tools)
        <div class="dk-ptools">{{ $tools }}</div>
    @endisset

    <div class="dk-pb">{{ $slot }}</div>

    @isset($foot)
        <footer class="dk-pf">{{ $foot }}</footer>
    @endisset
</section>
