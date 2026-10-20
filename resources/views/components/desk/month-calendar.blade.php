@props([
    'month',
    'items' => [],
    'model' => 'calendarMonth',
])

@php
    use Carbon\CarbonImmutable;

    /*
     * A register's Calendar view (desk.js renderRegister, view "cal"): one
     * month, weeks starting Monday, up to three chips a day then "+N".
     * $month is any date in the month; $items is a list of
     * ['date' => 'Y-m-d', 'label' => string, 'href' => ?string]. The arrows
     * write 'Y-m' to the page's $model property.
     */
    $first = CarbonImmutable::parse($month)->startOfMonth();
    $today = CarbonImmutable::today()->toDateString();
    $byDay = collect($items)->groupBy('date');
    $lead = ($first->dayOfWeekIso - 1);
    $weekdays = collect(range(0, 6))->map(fn (int $offset): string => CarbonImmutable::parse('2026-10-12')->addDays($offset)->translatedFormat('D'));
@endphp

<div {{ $attributes->class('dk-regcal') }} data-desk-calendar="{{ $first->format('Y-m') }}">
    <div class="dk-calbar">
        <button type="button" class="dk-iconbtn" wire:click="$set('{{ $model }}', '{{ $first->subMonth()->format('Y-m') }}')" aria-label="{{ __('desk.previous_month') }}" data-desk-calendar-previous><x-desk.icon name="back" class="dk-flip" /></button>
        <strong>{{ $first->translatedFormat('F Y') }}</strong>
        <button type="button" class="dk-iconbtn" wire:click="$set('{{ $model }}', '{{ $first->addMonth()->format('Y-m') }}')" aria-label="{{ __('desk.next_month') }}" data-desk-calendar-next><x-desk.icon name="chev" class="dk-flip" /></button>
    </div>
    <div class="dk-calgrid">
        @foreach ($weekdays as $weekday)
            <span class="dk-wd">{{ $weekday }}</span>
        @endforeach
        @for ($blank = 0; $blank < $lead; $blank++)
            <div class="dk-cday dk-off"></div>
        @endfor
        @for ($day = 1; $day <= $first->daysInMonth; $day++)
            @php
                $iso = $first->setDay($day)->toDateString();
                $dayItems = $byDay->get($iso, collect());
            @endphp
            <div @class(['dk-cday', 'dk-today' => $iso === $today]) wire:key="cal-{{ $iso }}" data-desk-calendar-day="{{ $iso }}">
                <span class="dk-cn dk-num">{{ $day }}</span>
                @foreach ($dayItems->take(3) as $item)
                    @if (! empty($item['href']))
                        <a class="dk-cchip" href="{{ $item['href'] }}" wire:navigate title="{{ $item['label'] }}">{{ $item['label'] }}</a>
                    @else
                        <span class="dk-cchip" title="{{ $item['label'] }}">{{ $item['label'] }}</span>
                    @endif
                @endforeach
                @if ($dayItems->count() > 3)
                    <span class="dk-more dk-small">{{ __('desk.register.more_items', ['count' => $dayItems->count() - 3]) }}</span>
                @endif
            </div>
        @endfor
    </div>
</div>
