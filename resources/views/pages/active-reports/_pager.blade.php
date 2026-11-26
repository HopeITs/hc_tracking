{{-- Server pager for the monthly grid: "1–15 of 111", rows per page, numbered pages with gaps. --}}
@php
    $pages = $pager['pages'];
    $current = $pager['page'];
    $numbers = collect([1, $pages, $current - 1, $current, $current + 1])->filter(fn (int $n): bool => $n >= 1 && $n <= $pages)->unique()->sort()->values();
@endphp

<nav class="rt-pager" aria-label="{{ __('active_reports.page_of', ['page' => $current, 'pages' => $pages]) }}" data-tracker-pager>
    <span class="rt-pager-count dk-num">{{ __('active_reports.showing_places', ['first' => $pager['first'], 'last' => $pager['last'], 'total' => $pager['total']]) }}</span>
    <label class="rt-pager-size">
        <span>{{ __('active_reports.per_page') }}</span>
        <select wire:model.live="perPage">
            @foreach ($this::PageSizes as $size)
                <option value="{{ $size }}">{{ $size }}</option>
            @endforeach
        </select>
    </label>
    @if ($pages > 1)
        <span class="rt-pager-pages">
            <button type="button" class="rt-iconbtn" wire:click="goToPage({{ $current - 1 }})" @disabled($current === 1) aria-label="{{ __('active_reports.previous') }}"><x-desk.icon name="back" class="dk-flip" /></button>
            @foreach ($numbers as $i => $number)
                @if ($i > 0 && $number - $numbers[$i - 1] > 1)<span class="rt-pager-gap">…</span>@endif
                <button type="button" class="rt-pagebtn dk-num" wire:click="goToPage({{ $number }})" @if ($number === $current) aria-current="page" @endif>{{ $number }}</button>
            @endforeach
            <button type="button" class="rt-iconbtn" wire:click="goToPage({{ $current + 1 }})" @disabled($current === $pages) aria-label="{{ __('active_reports.next') }}"><x-desk.icon name="back" class="rt-fwd" /></button>
        </span>
    @endif
</nav>
