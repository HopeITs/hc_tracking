{{--
    Footer of one framework list card: dashed slots that keep a capped card
    at four rows, then "Show all" (when rows are hidden) and the activity
    board link. Expects $cap, $total and $board.
--}}
@if ($cap !== null && $total > 0 && $total < $cap)
    @for ($slot = $total; $slot < $cap; $slot++)
        <div class="pp-fw-slot" aria-hidden="true"></div>
    @endfor
@endif
<div class="pp-fw-ft">
    @if ($cap !== null && $total > $cap)
        <button type="button" class="pp-link" x-on:click="all = ! all" x-text="all ? @js(__('proposals.framework.show_fewer')) : @js(__('proposals.framework.show_all', ['count' => $total]))">{{ __('proposals.framework.show_all', ['count' => $total]) }}</button>
    @endif
    @if ($board)
        <button type="button" class="pp-link pp-link-mute pp-fw-board" wire:click="setLayout('board')">{{ __('proposals.framework.edit_on_board') }}</button>
    @endif
</div>
