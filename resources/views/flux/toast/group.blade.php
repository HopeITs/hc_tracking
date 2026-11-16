{{--
    Overrides Flux's toast group (vendor/livewire/flux/stubs/.../toast/group.blade.php).

    How long a toast stays follows what it says: a success or a note goes after
    4s, a warning after 6s, and an error (danger) stays until it is closed.
    Flux::toast() always sends its own 5s default (and $flux.toast() leaves it
    unset), so only those get the app's life; a toast that asks for any other
    duration keeps it. The life is copied onto the new toast (data-life and
    --hc-toast-life) so its time-left line in flux-overrides.css runs for the
    same time. Flux's own timer pauses while any toast is hovered or the tab is
    hidden; hovering also fans the stack out, and the line pauses with it.

    Esc closes the newest toast, and only the toast: it is caught before an
    open modal underneath would also take it as "close".
--}}
@props([
    'position' => 'bottom end',
    'expanded' => false,
])

<ui-toast-group
    x-data
    x-on:toast-show.document="
        const detail = $event.detail;
        const life = [undefined, 5000].includes(detail.duration) ? ({ danger: 0, warning: 6000 }[detail.dataset?.variant] ?? 4000) : Number(detail.duration);
        $el.showToast({ ...detail, duration: life });
        $el.lastElementChild?.setAttribute('data-life', String(life));
        $el.lastElementChild?.style.setProperty('--hc-toast-life', life + 'ms');
    "
    x-on:keydown.escape.window.capture="const shown = $el.querySelector('[data-flux-toast-dialog]:last-child ui-close button'); if (shown) { $event.preventDefault(); $event.stopPropagation(); shown.click() }"
    popover="manual"
    position="{{ $position }}"
    {{ $expanded ? 'expanded' : '' }}
    wire:ignore
>
    {{ $slot }}
</ui-toast-group>
