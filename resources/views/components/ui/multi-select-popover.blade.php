@props(['label', 'triggerLabel' => null, 'searchable' => true, 'target' => 'body'])

{{--
    A searchable multi-select popover shared by the task list row's inline
    assignee picker and the task drawer's assignee field. Hand-rolled with
    Alpine (not flux:dropdown) because a Flux menu item closes the popover
    on the first click — wrong when picking several values in a row. Uses
    x-teleport so it isn't clipped by an overflow-hidden ancestor (the list
    panel's rounded corners, the drawer's scrolling body).

    `target` overrides the teleport destination (a CSS selector, default
    'body'). Flux modals render as a native <dialog>, which paints in the
    browser's top layer — a `fixed`/z-index element teleported to <body>
    sits outside that top layer and renders underneath the dialog no matter
    its z-index. Pass the dialog's own selector (e.g. a `[data-modal="..."]`
    match from a named flux:modal) when this popover lives inside one, so
    the teleported panel is a descendant of the dialog and shares its
    top-layer stacking instead of hiding behind it.

    An open <dialog> carries `transform: scale(...)` (flux.css), and a
    `transform` on an ancestor makes it the containing block for its
    `position: fixed` descendants (CSS spec). So once teleported inside the
    dialog, this panel's `top`/`left` can no longer be the raw viewport
    coordinates from `getBoundingClientRect()` — they must be taken relative
    to the dialog's own box, or the panel renders off-position and looks like
    it never opened. `toggle()` below subtracts the teleport target's own
    rect when `target` isn't 'body'; the 'body' case is left untouched since
    body is never transformed and this popover already works there.

    Slots:
    - trigger: the clickable element that opens the popover (an avatar
      cluster, a bordered select-like button, etc). Any extra attributes
      passed to this component (class, data-test, ...) land on the trigger.
    - default: the option rows. Each should carry
      x-show="optionSearch === '' || $el.textContent.toLowerCase().includes(optionSearch.toLowerCase())"
      to participate in the search filter.
--}}
<div
    x-data="{
        open: false,
        optionSearch: '',
        top: 0,
        left: 0,
        toggle() {
            this.open = ! this.open;
            if (this.open) {
                const rect = this.$refs.trigger.getBoundingClientRect();
                const target = @js($target);
                const container = target === 'body' ? null : document.querySelector(target);
                const containerRect = container ? container.getBoundingClientRect() : { top: 0, left: 0 };
                this.top = rect.bottom - containerRect.top + 4;
                this.left = rect.left - containerRect.left;
                this.$nextTick(() => this.$refs.search?.focus());
            }
        },
    }"
    x-on:keydown.escape.window="open = false"
    class="min-w-0"
>
    <div
        x-ref="trigger"
        role="button"
        tabindex="0"
        x-on:click="toggle()"
        x-on:keydown.enter="toggle()"
        x-on:keydown.space.prevent="toggle()"
        aria-haspopup="listbox"
        x-bind:aria-expanded="open ? 'true' : 'false'"
        {{ $attributes->merge(['aria-label' => $triggerLabel ?? $label]) }}
    >
        {{ $trigger }}
    </div>

    <template x-teleport="{{ $target }}">
        <div
            x-show="open"
            x-cloak
            x-on:click.outside="open = false"
            x-transition.opacity.duration.100ms
            x-bind:style="`top: ${top}px; left: ${left}px;`"
            role="listbox"
            aria-multiselectable="true"
            class="fixed z-50 w-64 rounded-lg border border-secondary-soft bg-white p-1 shadow-lg dark:border-secondary-medium dark:bg-secondary-dark"
        >
            @if ($searchable)
                <div class="border-b border-secondary-soft p-1.5 dark:border-secondary-medium">
                    <x-ui.input type="search" size="sm" icon="magnifying-glass" x-ref="search" x-model="optionSearch" :aria-label="$label" :placeholder="$label" />
                </div>
            @endif
            <div class="max-h-56 overflow-y-auto">
                {{ $slot }}
            </div>
        </div>
    </template>
</div>
