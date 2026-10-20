@props(['href' => null, 'click' => null])

{{--
    One Board card. With $href the whole card opens the record; with $click
    (a Livewire action such as "openTransferEdit(4)") it runs that action;
    otherwise it only shows. Lines inside use <strong> and .dk-is (desk.js
    board.card).
--}}
@if ($href !== null)
    <a href="{{ $href }}" wire:navigate {{ $attributes->class('dk-bcard') }} data-desk-board-card>{{ $slot }}</a>
@elseif ($click !== null)
    <button type="button" wire:click="{{ $click }}" {{ $attributes->class('dk-bcard') }} data-desk-board-card>{{ $slot }}</button>
@else
    <article {{ $attributes->class('dk-bcard') }} data-desk-board-card>{{ $slot }}</article>
@endif
