@props([
    'type',
    'label',
    'series' => [],
    'categories' => [],
    'tones' => [],
    'height' => 240,
    'currency' => null,
    'horizontal' => false,
    'distributed' => false,
    'totalLabel' => null,
    'empty' => null,
])

@php
    $flatValues = collect($series)->flatMap(fn ($entry) => is_array($entry) ? ($entry['data'] ?? []) : [$entry]);
    $isEmpty = $flatValues->sum() <= 0;
    $chartId = 'chart-'.substr(md5($label.json_encode([$series, $categories])), 0, 10);
    $config = [
        'type' => $type,
        'series' => $series,
        'categories' => array_values($categories),
        'tones' => array_values($tones),
        'height' => $height,
        'currency' => $currency,
        'horizontal' => $horizontal,
        'distributed' => $distributed,
        'totalLabel' => $totalLabel,
    ];
@endphp

<figure data-chart {{ $attributes->class('flex flex-col') }} role="group" aria-labelledby="{{ $chartId }}-caption">
    <figcaption id="{{ $chartId }}-caption" class="sr-only">{{ $label }}</figcaption>

    @if ($isEmpty)
        <div class="flex flex-col items-center justify-center gap-2 text-center" style="min-height: {{ $height }}px">
            <flux:icon.chart-pie class="size-6 text-secondary-light" />
            <p class="type-meta text-secondary-medium dark:text-ink-muted">{{ $empty ?? __('ui.no_records') }}</p>
        </div>
    @else
        <div
            wire:ignore
            wire:key="{{ $chartId }}"
            x-data="hcChart(@js($config))"
            dir="ltr"
            class="relative"
            style="min-height: {{ $height }}px"
        >
            <div x-show="! ready" class="absolute inset-0 animate-pulse rounded-lg bg-secondary-soft/50 dark:bg-surface-2" aria-hidden="true"></div>
            <div x-ref="canvas"></div>
        </div>

        <table class="sr-only">
            <caption>{{ $label }}</caption>
            <tbody>
                @if ($type === 'donut')
                    @foreach (array_values($categories) as $index => $category)
                        <tr><th scope="row">{{ $category }}</th><td>{{ $series[$index] ?? 0 }}</td></tr>
                    @endforeach
                @else
                    @foreach ($series as $entry)
                        @foreach (array_values($categories) as $index => $category)
                            <tr>
                                <th scope="row">{{ ($entry['name'] ?? '') !== '' ? $entry['name'].' · ' : '' }}{{ $category }}</th>
                                <td>{{ $entry['data'][$index] ?? 0 }}{{ $currency ? ' '.$currency : '' }}</td>
                            </tr>
                        @endforeach
                    @endforeach
                @endif
            </tbody>
        </table>
    @endif
</figure>
