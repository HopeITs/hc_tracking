@props(['label', 'value', 'denominator' => null, 'unit' => null, 'scope' => null, 'href' => null, 'tone' => null, 'icon' => null])

@php
    $valueToneClass = match ($tone) {
        'good' => 'text-status-good',
        'warn' => 'text-status-warn',
        'critical' => 'text-primary dark:text-brand-text',
        'funding' => 'text-funding',
        default => 'text-secondary-dark dark:text-ink',
    };

    $iconBadgeClasses = match ($tone) {
        'good' => 'bg-status-good-soft text-status-good',
        'warn' => 'bg-status-warn-soft text-status-warn',
        'critical' => 'bg-status-critical-soft text-primary dark:text-brand-text',
        'funding' => 'bg-funding-soft text-funding',
        default => 'bg-secondary-soft text-secondary-dark dark:bg-surface-2 dark:text-ink',
    };
@endphp

@if ($href !== null)
    <a
        href="{{ $href }}"
        wire:navigate
        data-stat-tile {{ $attributes->class('group block rounded-xl border border-secondary-soft bg-white p-4 shadow-card transition-all hover:-translate-y-0.5 hover:shadow-card-hover focus-visible:outline-2 focus-visible:outline-secondary-medium dark:border-transparent dark:bg-surface-1 dark:shadow-raised dark:hover:shadow-raised') }}
    >
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="type-label text-secondary-medium dark:text-ink-muted">{{ $label }}</p>
                <p class="type-figure mt-2 flex items-baseline gap-1 {{ $valueToneClass }}">
                    <span>{{ $value }}</span>
                    @if ($denominator !== null)
                        <span class="type-body font-normal text-secondary-medium dark:text-ink-muted">/ {{ $denominator }}</span>
                    @endif
                    @if ($unit !== null)
                        <span class="type-meta font-normal text-secondary-medium dark:text-ink-muted">{{ $unit }}</span>
                    @endif
                </p>
            </div>
            @if ($icon !== null)
                <div class="{{ $iconBadgeClasses }} flex size-10 shrink-0 items-center justify-center rounded-xl transition-transform group-hover:scale-105">
                    <flux:icon :name="$icon" class="size-5" />
                </div>
            @elseif ($scope !== null)
                <span class="rounded-full border border-secondary-light px-2 py-0.5 text-[11px] font-semibold text-secondary-medium dark:text-ink-muted">{{ $scope }}</span>
            @endif
        </div>
    </a>
@else
    <div data-stat-tile {{ $attributes->class('rounded-xl border border-secondary-soft bg-white p-4 shadow-card dark:border-transparent dark:bg-surface-1 dark:shadow-raised') }}>
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="type-label text-secondary-medium dark:text-ink-muted">{{ $label }}</p>
                <p class="type-figure mt-2 flex items-baseline gap-1 {{ $valueToneClass }}">
                    <span>{{ $value }}</span>
                    @if ($denominator !== null)
                        <span class="type-body font-normal text-secondary-medium dark:text-ink-muted">/ {{ $denominator }}</span>
                    @endif
                    @if ($unit !== null)
                        <span class="type-meta font-normal text-secondary-medium dark:text-ink-muted">{{ $unit }}</span>
                    @endif
                </p>
            </div>
            @if ($icon !== null)
                <div class="{{ $iconBadgeClasses }} flex size-10 shrink-0 items-center justify-center rounded-xl">
                    <flux:icon :name="$icon" class="size-5" />
                </div>
            @elseif ($scope !== null)
                <span class="rounded-full border border-secondary-light px-2 py-0.5 text-[11px] font-semibold text-secondary-medium dark:text-ink-muted">{{ $scope }}</span>
            @endif
        </div>
    </div>
@endif
