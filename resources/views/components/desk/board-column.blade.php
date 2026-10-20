@props(['label', 'count' => 0, 'key' => null])

{{-- One Board column: its name and count, then the cards; "Empty" when there are none. --}}
<section {{ $attributes->class('dk-bcol') }} @if ($key !== null) data-desk-board-column="{{ $key }}" @endif>
    <h3>{{ $label }} <span class="dk-num">{{ $count }}</span></h3>
    <div class="dk-bcards">
        @if ($count > 0)
            {{ $slot }}
        @else
            <p class="dk-pempty">{{ __('desk.register.empty_column') }}</p>
        @endif
    </div>
</section>
