@props(['value' => null, 'size' => 'md', 'tone' => 'neutral', 'label' => null])

@php
    $sizePx = match ($size) {
        'sm' => 28,
        'lg' => 96,
        default => 48,
    };
    $stroke = match ($size) {
        'sm' => 3,
        'lg' => 8,
        default => 5,
    };
    $radius = ($sizePx / 2) - $stroke;
    $circumference = 2 * M_PI * $radius;
    $pct = $value === null ? 0 : max(0, min(100, $value));
    $offset = $circumference - ($pct / 100) * $circumference;

    $strokeClasses = match ($tone) {
        'good' => 'stroke-status-good',
        'warn' => 'stroke-status-warn',
        'critical' => 'stroke-primary',
        'funding' => 'stroke-funding',
        default => 'stroke-secondary-dark dark:stroke-white',
    };
@endphp

<div
    {{ $attributes->class('relative inline-flex shrink-0 items-center justify-center') }}
    style="width: {{ $sizePx }}px; height: {{ $sizePx }}px"
    role="progressbar"
    aria-valuemin="0"
    aria-valuemax="100"
    @if ($value !== null) aria-valuenow="{{ round($value) }}" @endif
    @if ($label !== null) aria-label="{{ $label }}" @endif
>
    <svg width="{{ $sizePx }}" height="{{ $sizePx }}" viewBox="0 0 {{ $sizePx }} {{ $sizePx }}" class="-rotate-90" aria-hidden="true">
        <circle
            cx="{{ $sizePx / 2 }}"
            cy="{{ $sizePx / 2 }}"
            r="{{ $radius }}"
            fill="none"
            stroke-width="{{ $stroke }}"
            class="stroke-secondary-soft dark:stroke-secondary-medium"
        />
        @if ($value !== null)
            <circle
                cx="{{ $sizePx / 2 }}"
                cy="{{ $sizePx / 2 }}"
                r="{{ $radius }}"
                fill="none"
                stroke-width="{{ $stroke }}"
                stroke-linecap="butt"
                stroke-dasharray="{{ $circumference }}"
                stroke-dashoffset="{{ $offset }}"
                class="{{ $strokeClasses }} transition-[stroke-dashoffset] duration-500 motion-reduce:transition-none"
            />
        @endif
    </svg>

    @if ($size === 'lg')
        <span class="type-figure absolute text-secondary-dark tabular-nums dark:text-white" aria-hidden="true">
            {{ $value === null ? '—' : round($value).'%' }}
        </span>
    @endif
</div>
