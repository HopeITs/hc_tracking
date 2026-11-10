@props(['route', 'label'])

{{-- The pin beside a sidebar link: pins the page to the top of the sidebar, or unpins it. --}}
<button
    type="button"
    class="dk-side-pin"
    x-on:click.prevent.stop="pin(@js($route))"
    x-bind:class="{ 'dk-pinned': pins.includes(@js($route)) }"
    x-bind:aria-pressed="pins.includes(@js($route)).toString()"
    x-bind:aria-label="pins.includes(@js($route)) ? @js(__('navigation.unpin_page', ['page' => $label])) : @js(__('navigation.pin_page', ['page' => $label]))"
    data-side-pin="{{ $route }}"
>
    <x-desk.icon name="pushpin" class="dk-pin-ic" />
</button>
