@props(['label', 'triggerLabel' => null, 'searchable' => true, 'inModal' => false])

{{--
    A searchable multi-select popover shared by the task list row's inline
    assignee picker and the task drawer's assignee field. Hand-rolled with
    Alpine (not flux:dropdown) because a Flux menu item closes the popover
    on the first click — wrong when picking several values in a row. Uses
    x-teleport so it isn't clipped by an overflow-hidden ancestor (the list
    panel's rounded corners, the drawer's scrolling body).

    Pass `in-modal` when the picker sits inside a flux:modal. A Flux modal is
    a native <dialog> opened with showModal(): it paints in the browser's top
    layer and makes the rest of the page inert, so a panel teleported to
    <body> renders underneath the dialog and can't be clicked. Teleporting
    into the dialog fails too: the dialog is part of the Livewire component's
    own DOM, so the next morph — including the one that opens the modal —
    deletes the panel as a child the server never rendered. In modal mode
    the panel is therefore rendered in place, as part of the server HTML
    Livewire morphs, and lifted into the top layer with the Popover API
    (popover="manual" + showPopover(), the same mechanism Flux's own
    dropdowns use). The top layer paints above the open dialog and escapes
    its transform and overflow clipping, while staying a DOM descendant of
    the dialog keeps the panel interactive. Top-layer boxes are positioned
    against the viewport, so the trigger's getBoundingClientRect() applies
    as-is; the panel flips above the trigger when it would otherwise run off
    the bottom of the viewport, and closes (with its search cleared) along
    with its dialog, so a reopened modal never starts on a stale filter.

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
        bottom: null,
        left: 0,
        toggle() {
            this.open = ! this.open;
            if (this.open) {
                const rect = this.$refs.trigger.getBoundingClientRect();
                this.top = rect.bottom + 4;
                this.left = rect.left;
                this.$nextTick(() => this.$refs.search?.focus());
            }
        },
        syncTopLayerPanel(panel) {
            if (this.open === panel.matches(':popover-open')) {
                return;
            }
            if (! this.open) {
                panel.hidePopover();
                return;
            }
            this.bottom = null;
            panel.showPopover();
            const rect = this.$refs.trigger.getBoundingClientRect();
            const viewportHeight = document.documentElement.clientHeight;
            const panelHeight = panel.offsetHeight;
            if (rect.bottom + 4 + panelHeight > viewportHeight && rect.top - 4 - panelHeight >= 0) {
                this.bottom = viewportHeight - rect.top + 4;
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

    @if ($inModal)
        <div
            popover="manual"
            x-init="$el.closest('dialog')?.addEventListener('close', () => { open = false; optionSearch = ''; })"
            x-effect="syncTopLayerPanel($el)"
            x-on:click.outside="$refs.trigger.contains($event.target) || (open = false)"
            x-bind:style="bottom === null ? `top: ${top}px; left: ${left}px;` : `bottom: ${bottom}px; left: ${left}px;`"
            role="listbox"
            aria-multiselectable="true"
            class="fixed inset-auto m-0 w-64 overflow-visible rounded-lg border border-secondary-soft bg-white p-1 text-inherit shadow-lg transition-opacity duration-100 starting:open:opacity-0 dark:border-secondary-medium dark:bg-secondary-dark"
        >
            @if ($searchable)
                <div class="border-b border-secondary-soft p-1.5 dark:border-secondary-medium">
                    <x-ui.input type="search" size="sm" icon="magnifying-glass" x-ref="search" x-model="optionSearch" x-on:keydown.enter.prevent :aria-label="$label" :placeholder="$label" />
                </div>
            @endif
            <div class="max-h-56 overflow-y-auto">
                {{ $slot }}
            </div>
        </div>
    @else
        <template x-teleport="body">
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
    @endif
</div>
