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
        data-stat-tile {{ $attributes->class('group block rounded-xl border border-secondary-soft bg-white p-4 shadow-[0_1px_2px_rgba(16,24,40,0.04),0_1px_3px_rgba(16,24,40,0.06)] transition-all hover:-translate-y-0.5 hover:shadow-[0_2px_4px_rgba(16,24,40,0.06),0_4px_8px_rgba(16,24,40,0.08)] focus-visible:outline-2 focus-visible:outline-primary dark:border-secondary-medium dark:bg-secondary-dark dark:shadow-none dark:hover:shadow-none') }}
    >
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="type-label text-secondary-medium dark:text-secondary-soft">{{ $label }}</p>
                <p class="type-figure mt-2 flex items-baseline gap-1 {{ $valueToneClass }}">
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
                <div class="{{ $iconBadgeClasses }} flex size-10 shrink-0 items-center justify-center rounded-xl transition-transform group-hover:scale-105">
                    <flux:icon :name="$icon" class="size-5" />
                </div>
            @elseif ($scope !== null)
                <span class="rounded-full border border-secondary-light px-2 py-0.5 text-[11px] font-semibold text-secondary-medium dark:text-secondary-soft">{{ $scope }}</span>
            @endif
        </div>
    </a>
@else
    <div data-stat-tile {{ $attributes->class('rounded-xl border border-secondary-soft bg-white p-4 shadow-[0_1px_2px_rgba(16,24,40,0.04),0_1px_3px_rgba(16,24,40,0.06)] dark:border-secondary-medium dark:bg-secondary-dark dark:shadow-none') }}>
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="type-label text-secondary-medium dark:text-secondary-soft">{{ $label }}</p>
                <p class="type-figure mt-2 flex items-baseline gap-1 {{ $valueToneClass }}">
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
                <div class="{{ $iconBadgeClasses }} flex size-10 shrink-0 items-center justify-center rounded-xl">
                    <flux:icon :name="$icon" class="size-5" />
                </div>
            @elseif ($scope !== null)
                <span class="rounded-full border border-secondary-light px-2 py-0.5 text-[11px] font-semibold text-secondary-medium dark:text-secondary-soft">{{ $scope }}</span>
            @endif
        </div>
    </div>
@endif
