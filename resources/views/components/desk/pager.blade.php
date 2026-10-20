@props(['pg'])

{{-- A panel footer: "1–5 of 15" and the page arrows, or "3 shown" when everything fits. Calls the panel's own previousPage()/nextPage() (IsDeskPanel). --}}
@if ($pg['total'] > $pg['per'])
    <span class="dk-muted dk-small dk-num">{{ __('desk.range_of', ['from' => $pg['from'], 'to' => $pg['to'], 'total' => $pg['total']]) }}</span>
    <span class="dk-pgb">
        <button type="button" class="dk-iconbtn dk-sm" wire:click="previousPage" @if ($pg['page'] === 0) aria-disabled="true" disabled @endif aria-label="{{ __('desk.previous_page') }}"><x-desk.icon name="back" class="dk-flip" /></button>
        <span class="dk-num dk-small">{{ $pg['page'] + 1 }}/{{ $pg['pages'] }}</span>
        <button type="button" class="dk-iconbtn dk-sm" wire:click="nextPage" @if ($pg['page'] >= $pg['pages'] - 1) aria-disabled="true" disabled @endif aria-label="{{ __('desk.next_page') }}"><x-desk.icon name="chev" class="dk-flip" /></button>
    </span>
@elseif ($pg['total'] > 0)
    <span class="dk-muted dk-small">{{ trans_choice('desk.shown', $pg['total'], ['count' => $pg['total']]) }}</span>
@endif
