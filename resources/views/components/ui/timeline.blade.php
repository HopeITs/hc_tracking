@props([
    'from' => null,
    'to' => null,
    'items' => [],
])

@php
    use Illuminate\Support\Carbon;

    $rows = collect($items)->values();

    $windowStart = $from ?? $rows->map(fn ($item) => $item['start'] ?? null)->filter()->min();
    $windowEnd = $to ?? $rows->map(fn ($item) => $item['end'] ?? $item['start'] ?? null)->filter()->max();

    $totalDays = ($windowStart && $windowEnd) ? max($windowStart->diffInDays($windowEnd), 1) : 1;

    $position = function (?Carbon $date) use ($windowStart, $totalDays): float {
        if (! $date || ! $windowStart) {
            return 0.0;
        }

        return max(0, min(100, ($windowStart->diffInDays($date, false) / $totalDays) * 100));
    };

    $today = Carbon::today();
    $todayPct = ($windowStart && $windowEnd && $today->between($windowStart, $windowEnd)) ? $position($today) : null;

    // Long windows (most strategy/programme spans run several years) get one
    // tick per year instead of per month, so the ruler stays readable instead
    // of a dense cluster of overlapping month labels.
    $ticks = collect();
    $ticksByYear = ($windowStart && $windowEnd) ? $windowStart->diffInMonths($windowEnd) > 24 : false;
    if ($windowStart && $windowEnd) {
        $cursor = $ticksByYear ? $windowStart->copy()->startOfYear() : $windowStart->copy()->startOfMonth();
        $guard = 0;
        $limit = $ticksByYear ? 60 : 240;
        while ($cursor->lte($windowEnd) && $guard < $limit) {
            $ticks->push($cursor->copy());
            $ticksByYear ? $cursor->addYear() : $cursor->addMonthNoOverflow();
            $guard++;
        }
    }

    $toneClasses = fn (string $tone): string => match ($tone) {
        'good' => 'bg-status-good',
        'warn' => 'bg-status-warn',
        'critical' => 'bg-primary',
        'funding' => 'bg-funding',
        default => 'bg-secondary-dark dark:bg-white',
    };
@endphp

<div {{ $attributes->class('flex flex-col gap-1') }}>
    @if ($ticks->isNotEmpty())
        <div class="relative hidden h-4 @2xl:block" aria-hidden="true">
            @foreach ($ticks as $tick)
                <span class="type-meta absolute top-0 tabular-nums text-secondary-light dark:text-secondary-medium" style="inset-inline-start: {{ $position($tick) }}%">
                    {{ $ticksByYear ? $tick->format('Y') : $tick->translatedFormat(__('formats.month_short')) }}
                </span>
            @endforeach
        </div>
    @endif

    <div class="flex flex-col divide-y divide-board-line rounded-lg border border-board-line bg-board">
        @if ($rows->isEmpty())
            {{ $slot }}
        @endif

        @foreach ($rows as $item)
            @php
                $start = $item['start'] ?? null;
                $end = $item['end'] ?? $start;
                $startPct = $position($start);
                $endPct = $position($end);
                $width = max($endPct - $startPct, 1.5);
                $tone = $item['tone'] ?? 'neutral';
            @endphp
            <div class="flex flex-col gap-2 p-3 @2xl:flex-row @2xl:items-center @2xl:gap-4" wire:key="timeline-{{ $item['key'] }}">
                <div class="min-w-0 @2xl:w-52 @2xl:shrink-0">
                    @if (! empty($item['href']))
                        <a href="{{ $item['href'] }}" wire:navigate class="truncate text-sm font-semibold text-secondary-dark hover:underline dark:text-white">{{ $item['label'] }}</a>
                    @else
                        <p class="truncate text-sm font-semibold text-secondary-dark dark:text-white">{{ $item['label'] }}</p>
                    @endif
                    @if (! empty($item['sublabel']))
                        <p class="type-meta text-secondary-medium dark:text-secondary-soft">{{ $item['sublabel'] }}</p>
                    @endif
                </div>

                <div class="relative hidden h-2 min-w-0 flex-1 @2xl:block" aria-label="{{ $start?->translatedFormat(__('formats.date')) }} – {{ $end?->translatedFormat(__('formats.date')) }}">
                    <div class="absolute inset-y-0 start-0 end-0 rounded-full bg-secondary-soft dark:bg-secondary-medium"></div>
                    <div
                        class="{{ $toneClasses($tone) }} absolute inset-y-0 rounded-full"
                        style="inset-inline-start: {{ $startPct }}%; width: {{ $width }}%"
                    ></div>
                    @if ($todayPct !== null)
                        <div class="absolute -top-1 -bottom-1 w-px bg-primary" style="inset-inline-start: {{ $todayPct }}%" aria-hidden="true" title="{{ __('ui.today') }}"></div>
                    @endif
                </div>

                <p class="type-meta text-secondary-medium dark:text-secondary-soft @2xl:hidden">
                    <bdi>{{ $start?->translatedFormat(__('formats.date')) }} – {{ $end?->translatedFormat(__('formats.date')) }}</bdi>
                </p>
            </div>
        @endforeach
    </div>
</div>
