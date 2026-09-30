@props([
    'value' => null,
    'max' => 100,
    'tone' => 'neutral',
    'label' => null,
    'labelHidden' => false,
    'showValue' => false,
])

@php
    $isOver = $value !== null && $value > $max;
    $effectiveTone = $isOver ? 'warn' : $tone;
    $pct = $value === null ? 0 : max(0, min(100, ($max > 0 ? ($value / $max) * 100 : 0)));

    $fillClasses = match ($effectiveTone) {
        'good' => 'bg-status-good',
        'warn' => 'bg-status-warn',
        'critical' => 'bg-primary dark:bg-status-critical',
        'funding' => 'bg-funding',
        default => 'bg-secondary-dark dark:bg-ink-muted',
    };
@endphp

<div {{ $attributes->class('flex flex-col gap-1') }}>
    @if (($label !== null && ! $labelHidden) || $showValue)
        <div class="flex items-center gap-2 {{ $label !== null && ! $labelHidden ? 'justify-between' : 'justify-end' }}">
            @if ($label !== null && ! $labelHidden)
                <p class="type-meta text-secondary-medium dark:text-ink-muted">{{ $label }}</p>
            @endif
            @if ($showValue)
                <p class="type-meta tabular-nums text-secondary-dark dark:text-ink">
                    {{ $value === null ? '—' : ($isOver ? __('ui.over_by', ['percent' => round($value - $max)]) : round($pct).'%') }}
                </p>
            @endif
        </div>
    @endif

    <div
        class="h-1.5 w-full overflow-hidden rounded-full bg-secondary-soft dark:bg-surface-2"
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
