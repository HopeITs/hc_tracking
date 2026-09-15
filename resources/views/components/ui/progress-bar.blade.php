@props([
    'value' => null,
    'max' => 100,
    'tone' => 'neutral',
    'label' => null,
    'showValue' => false,
])

@php
    $isOver = $value !== null && $value > $max;
    $effectiveTone = $isOver ? 'warn' : $tone;
    $pct = $value === null ? 0 : max(0, min(100, ($max > 0 ? ($value / $max) * 100 : 0)));

    $fillClasses = match ($effectiveTone) {
        'good' => 'bg-status-good',
        'warn' => 'bg-status-warn',
        'critical' => 'bg-primary',
        'funding' => 'bg-funding',
        default => 'bg-secondary-dark dark:bg-white',
    };
@endphp

<div {{ $attributes->class('flex flex-col gap-1') }}>
    @if ($label !== null || $showValue)
        <div class="flex items-center justify-between gap-2">
            @if ($label !== null)
                <p class="type-meta text-secondary-medium dark:text-secondary-soft">{{ $label }}</p>
            @endif
            @if ($showValue)
                <p class="type-meta tabular-nums text-secondary-dark dark:text-white">
                    {{ $value === null ? '—' : ($isOver ? __('ui.over_by', ['percent' => round($value - $max)]) : round($pct).'%') }}
                </p>
            @endif
        </div>
    @endif

    <div
        class="h-1.5 w-full overflow-hidden rounded-full bg-secondary-soft dark:bg-secondary-medium"
        role="progressbar"
        aria-valuemin="0"
        aria-valuemax="{{ $max }}"
        @if ($value !== null) aria-valuenow="{{ $value }}" @endif
        @if ($label !== null) aria-label="{{ $label }}" @endif
    >
        @if ($value !== null)
            <div class="{{ $fillClasses }} h-full rounded-full" style="width: {{ $isOver ? 100 : $pct }}%"></div>
        @endif
    </div>
</div>
