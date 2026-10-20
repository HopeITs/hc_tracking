{{--
    A register's Board view (desk.js renderRegister, view "board"): columns
    side by side that scroll sideways. Pages put x-desk.board-column inside,
    and x-desk.board-card inside each column, so every page keeps its own
    links and actions.
--}}
<div {{ $attributes->class('dk-board') }} data-desk-board>
    {{ $slot }}
</div>
