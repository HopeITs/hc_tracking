@props(['label', 'value', 'denominator' => null, 'unit' => null, 'scope' => null, 'href' => null, 'tone' => null, 'icon' => null])

@php
    $valueToneClass = match ($tone) {
        'good' => 'text-status-good',
        'warn' => 'text-status-warn',
        'critical' => 'text-primary',
        'funding' => 'text-funding',
        default => 'text-secondary-dark dark:text-white',
    };

    $iconBadgeClasses = match ($tone) {
        'good' => 'bg-status-good-soft text-status-good',
        'warn' => 'bg-status-warn-soft text-status-warn',
        'critical' => 'bg-status-critical-soft text-primary',
        'funding' => 'bg-funding-soft text-funding',
        default => 'bg-secondary-soft text-secondary-dark dark:bg-secondary-medium dark:text-white',
    };
@endphp

@if ($href !== null)
    <a
        href="{{ $href }}"
        wire:navigate
        {{ $attributes->class('group block rounded-lg border border-secondary-light bg-white p-3.5 transition-colors hover:border-secondary-medium hover:shadow-[0_1px_0_0_rgba(0,0,0,0.02)] focus-visible:outline-2 focus-visible:outline-primary dark:border-secondary-medium dark:bg-secondary-dark dark:hover:border-secondary-light') }}
    >
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="type-label text-secondary-medium dark:text-secondary-soft">{{ $label }}</p>
                <p class="type-figure mt-1.5 flex items-baseline gap-1 {{ $valueToneClass }}">
                    <span>{{ $value }}</span>
                    @if ($denominator !== null)
                        <span class="type-body font-normal text-secondary-medium dark:text-secondary-soft">/ {{ $denominator }}</span>
                    @endif
                    @if ($unit !== null)
                        <span class="type-meta font-normal text-secondary-medium dark:text-secondary-soft">{{ $unit }}</span>
                    @endif
                </p>
            </div>
            @if ($icon !== null)
                <div class="{{ $iconBadgeClasses }} flex size-9 shrink-0 items-center justify-center rounded-md transition-transform group-hover:scale-105">
                    <flux:icon :name="$icon" class="size-4.5" />
                </div>
            @elseif ($scope !== null)
                <span class="rounded-md border border-secondary-light px-1.5 py-0.5 text-[11px] font-semibold text-secondary-medium dark:text-secondary-soft">{{ $scope }}</span>
            @endif
        </div>
    </a>
@else
    <div {{ $attributes->class('rounded-lg border border-secondary-light bg-white p-3.5 dark:border-secondary-medium dark:bg-secondary-dark') }}>
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="type-label text-secondary-medium dark:text-secondary-soft">{{ $label }}</p>
                <p class="type-figure mt-1.5 flex items-baseline gap-1 {{ $valueToneClass }}">
                    <span>{{ $value }}</span>
                    @if ($denominator !== null)
                        <span class="type-body font-normal text-secondary-medium dark:text-secondary-soft">/ {{ $denominator }}</span>
                    @endif
                    @if ($unit !== null)
                        <span class="type-meta font-normal text-secondary-medium dark:text-secondary-soft">{{ $unit }}</span>
                    @endif
                </p>
            </div>
            @if ($icon !== null)
                <div class="{{ $iconBadgeClasses }} flex size-9 shrink-0 items-center justify-center rounded-md">
                    <flux:icon :name="$icon" class="size-4.5" />
                </div>
            @elseif ($scope !== null)
                <span class="rounded-md border border-secondary-light px-1.5 py-0.5 text-[11px] font-semibold text-secondary-medium dark:text-secondary-soft">{{ $scope }}</span>
            @endif
        </div>
    </div>
@endif
