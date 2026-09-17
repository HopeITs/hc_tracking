@props([
    'from' => null,
    'to' => null,
    'items' => [],
])

@php
    use Illuminate\Support\Carbon;

    // Dates built with Carbon::create() default any omitted time component to
    // the current time-of-day rather than midnight, so two otherwise-aligned
    // dates can carry different times. diffInDays() then truncates the
    // fractional day between them, quietly shifting every position (most
    // visibly the "today" marker) by up to a day. Normalizing everything to
    // midnight here neutralizes that regardless of how callers built the dates.
    $atMidnight = fn (?Carbon $date): ?Carbon => $date?->copy()->startOfDay();

    $rows = collect($items)->map(function ($item) use ($atMidnight) {
        $item['start'] = $atMidnight($item['start'] ?? null);
        $item['end'] = $atMidnight($item['end'] ?? null) ?? $item['start'];

        return $item;
    })->values();

    $windowStart = $atMidnight($from) ?? $rows->map(fn ($item) => $item['start'] ?? null)->filter()->min();
    $windowEnd = $atMidnight($to) ?? $rows->map(fn ($item) => $item['end'] ?? $item['start'] ?? null)->filter()->max();

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
        {{-- The ruler mirrors each row's own box (label column + gap + horizontal
             padding) so a given percentage lands at the same pixel position in
             both places; without this spacer the ruler spans the full width
             while the bars below sit inset by the label column, and every tick
             (and the today line) drifts out of alignment with the row bars. --}}
        <div class="hidden @2xl:block" aria-hidden="true">
            <div class="flex items-center gap-4 px-3">
                <div class="shrink-0" style="width: 13rem"></div>
                <div class="relative h-4 min-w-0 flex-1">
                    @foreach ($ticks as $tick)
                        <span class="type-meta absolute top-0 tabular-nums text-secondary-light dark:text-secondary-medium" style="inset-inline-start: {{ $position($tick) }}%">
                            {{ $ticksByYear ? $tick->format('Y') : $tick->translatedFormat(__('formats.month_short')) }}
                        </span>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    <div class="flex flex-col divide-y divide-board-line rounded-xl border border-board-line bg-board shadow-[0_1px_2px_rgba(16,24,40,0.04),0_1px_3px_rgba(16,24,40,0.06)] dark:shadow-none">
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
                        <flux:tooltip :content="__('ui.today_marker_hint', ['date' => $today->translatedFormat(__('formats.date'))])">
                            <div class="absolute -top-1.5 -bottom-1.5 w-2 -ms-1 cursor-help" style="inset-inline-start: {{ $todayPct }}%">
                                <div class="mx-auto h-full w-px bg-primary"></div>
                            </div>
                        </flux:tooltip>
                    @endif
                </div>

                <p class="type-meta text-secondary-medium dark:text-secondary-soft @2xl:hidden">
                    <bdi>{{ $start?->translatedFormat(__('formats.date')) }} – {{ $end?->translatedFormat(__('formats.date')) }}</bdi>
                </p>
            </div>
        @endforeach
    </div>
</div>
