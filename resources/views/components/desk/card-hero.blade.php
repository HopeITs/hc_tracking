@props(['hero'])

{{--
    A page card's own picture (App\Support\Desk\Cards\PageCard::hero): one headline figure
    and the visual that fits the page — a year ruler for strategies, a ring for planned
    programs, a pyramid of result levels, a status flow, a calendar of weeks, coins per
    currency, faces, a podium of cities… Styles: desk-workspace.css, .dk-hero.
--}}
@php
    $kind = $hero['kind'];
    $items = $hero['items'] ?? [];
@endphp

<div class="dk-hero" data-hero="{{ $kind }}" data-tone="{{ $hero['tone'] }}" data-card-hero>
    <p class="dk-hero-head">
        <strong class="dk-num">{{ $hero['big'] }}</strong>
        <span>{{ $hero['caption'] }}</span>
    </p>

    @switch($kind)
        @case('ruler')
            <div class="dk-hero-ruler" style="--now: {{ $hero['now'] }}%" aria-hidden="true">
                @foreach ($items as $item)
                    <span class="dk-hero-span" data-tone="{{ $item['tone'] }}" style="--from: {{ $item['from'] }}%; --to: {{ $item['to'] }}%"><b>{{ $item['label'] }}</b></span>
                @endforeach
                <span class="dk-hero-ticks">
                    @foreach ($hero['ticks'] as $tick)
                        <i style="--at: {{ $tick['at'] }}%">{{ $tick['label'] }}</i>
                    @endforeach
                </span>
                <em class="dk-hero-now"></em>
            </div>
            @break

        @case('ring')
        @case('gauge')
            <div class="dk-hero-meter">
                <span class="dk-hero-dial" style="--share: {{ $hero['share'] }}" aria-hidden="true"></span>
                <ul class="dk-hero-legend">
                    @foreach ($items as $item)
                        <li data-tone="{{ $item['tone'] }}"><i></i><span>{{ $item['label'] }}</span><b class="dk-num">{{ $item['value'] }}</b></li>
                    @endforeach
                </ul>
            </div>
            @break

        @case('years')
        @case('pulse')
            <ol class="dk-hero-cols" aria-hidden="true">
                @foreach ($items as $item)
                    <li @class(['dk-on' => $item['on'] ?? false]) data-tone="{{ $item['tone'] }}" title="{{ $item['label'] }} · {{ $item['value'] }}">
                        @if ($kind === 'years')<b class="dk-num">{{ $item['value'] }}</b>@endif
                        <i style="--share: {{ $item['share'] }}"></i>
                        @if ($kind === 'years')<span>{{ $item['label'] }}</span>@endif
                    </li>
                @endforeach
            </ol>
            @break

        @case('pyramid')
            <ol class="dk-hero-pyramid">
                @foreach ($items as $item)
                    <li data-tone="{{ $item['tone'] }}" style="--share: {{ $item['share'] }}%"><span>{{ $item['label'] }}</span><b class="dk-num">{{ $item['value'] }}</b></li>
                @endforeach
            </ol>
            @break

        @case('tally')
        @case('coins')
        @case('tags')
            <ul class="dk-hero-chips">
                @foreach ($items as $item)
                    <li data-tone="{{ $item['tone'] }}"><b class="dk-num">{{ $item['value'] }}</b><span>{{ $item['label'] }}</span></li>
                @endforeach
            </ul>
            @break

        @case('flow')
            <ol class="dk-hero-flow">
                @foreach ($items as $item)
                    <li data-tone="{{ $item['tone'] }}"><b class="dk-num">{{ $item['value'] }}</b><span>{{ $item['label'] }}</span></li>
                @endforeach
            </ol>
            @break

        @case('weeks')
            <ol class="dk-hero-weeks">
                @foreach ($items as $item)
                    <li data-tone="{{ $item['tone'] }}" title="{{ $item['label'] }} · {{ $item['value'] }}">
                        <span class="dk-hero-pips" aria-hidden="true">@for ($pip = 0; $pip < min(4, $item['count']); $pip++)<i></i>@endfor</span>
                        <span>{{ $item['label'] }}</span>
                    </li>
                @endforeach
            </ol>
            @break

        @case('money')
            <ul class="dk-hero-money">
                @foreach ($items as $item)
                    <li data-tone="{{ $item['tone'] }}">
                        <b class="dk-num">{{ $item['value'] }}</b><span>{{ $item['label'] }}</span>
                        <i style="--share: {{ $item['share'] }}%" aria-hidden="true"></i>
                        <small>{{ $item['note'] }}</small>
                    </li>
                @endforeach
            </ul>
            @break

        @case('faces')
            <div class="dk-hero-faces">
                <span class="dk-hero-stack" aria-hidden="true">
                    @foreach ($hero['faces'] ?? [] as $face)
                        <i data-tone="{{ $face['tone'] }}">{{ $face['label'] }}</i>
                    @endforeach
                </span>
                <ul class="dk-hero-chips">
                    @foreach ($items as $item)
                        <li data-tone="{{ $item['tone'] }}"><b class="dk-num">{{ $item['value'] }}</b><span>{{ $item['label'] }}</span></li>
                    @endforeach
                </ul>
            </div>
            @break

        @case('podium')
            @php
                /* Second, first, third: the leader stands in the middle. */
                $order = count($items) === 3 ? [$items[1], $items[0], $items[2]] : $items;
            @endphp
            <ol class="dk-hero-podium">
                @foreach ($order as $item)
                    <li style="--share: {{ $item['share'] }}" data-tone="{{ $item['tone'] }}"><span>{{ $item['label'] }}</span><i><b class="dk-num">{{ $item['value'] }}</b></i></li>
                @endforeach
            </ol>
            @break

        @case('bubbles')
            <ul class="dk-hero-bubbles">
                @foreach ($items as $item)
                    <li style="--share: {{ $item['share'] }}" data-tone="{{ $item['tone'] }}" title="{{ $item['label'] }} · {{ $item['value'] }}"><b class="dk-num">{{ $item['value'] }}</b><span>{{ $item['label'] }}</span></li>
                @endforeach
            </ul>
            @break

        @case('waffle')
            <ul class="dk-hero-waffle">
                @foreach ($items as $item)
                    <li @class(['dk-on' => $item['on']]) title="{{ $item['label'] }}"><span>{{ $item['label'] }}</span></li>
                @endforeach
            </ul>
            @break

        @case('dots')
            <ul class="dk-hero-dots">
                @foreach ($items as $item)
                    <li data-tone="{{ $item['tone'] }}">
                        <span>{{ $item['label'] }}</span>
                        <span class="dk-hero-pips" aria-hidden="true">@for ($pip = 0; $pip < $item['count']; $pip++)<i></i>@endfor</span>
                        <b class="dk-num">{{ $item['value'] }}</b>
                    </li>
                @endforeach
            </ul>
            @break
    @endswitch
</div>
