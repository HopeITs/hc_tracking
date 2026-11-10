@props(['route', 'label'])

{{-- On My desk, the "+" beside a sidebar link: puts that page on the Desk (as its panel, card or button), the keyboard and touch way to what dragging the link does. --}}
<button
    type="button"
    class="dk-side-add"
    x-on:click.prevent.stop="window.hcDeskItems?.place(@js($route))"
    aria-label="{{ __('desk-workspace.add.page_to_desk', ['page' => $label]) }}"
    title="{{ __('desk-workspace.add.drag_hint') }}"
    data-side-add="{{ $route }}"
>
    <flux:icon.plus variant="micro" class="size-3.5" />
</button>
