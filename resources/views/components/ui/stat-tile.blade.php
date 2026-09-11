@props(['label', 'value', 'denominator' => null, 'unit' => null, 'scope' => null])

<div {{ $attributes->class('rounded-lg border border-secondary-light bg-white p-3.5 dark:border-secondary-medium dark:bg-secondary-dark') }}>
    <div class="flex items-center justify-between gap-2">
        <p class="text-xs font-semibold tracking-wide text-secondary-medium uppercase dark:text-secondary-soft">{{ $label }}</p>
        @if ($scope !== null)
            <span class="rounded-md border border-secondary-light px-1.5 py-0.5 text-[11px] font-semibold text-secondary-medium dark:text-secondary-soft">{{ $scope }}</span>
        @endif
    </div>
    <p class="mt-1.5 flex items-baseline gap-1 tabular-nums">
        <span class="text-2xl font-semibold text-secondary-dark dark:text-white">{{ $value }}</span>
        @if ($denominator !== null)
            <span class="text-sm text-secondary-medium dark:text-secondary-soft">/ {{ $denominator }}</span>
        @endif
        @if ($unit !== null)
            <span class="text-xs text-secondary-medium dark:text-secondary-soft">{{ $unit }}</span>
        @endif
    </p>
</div>
