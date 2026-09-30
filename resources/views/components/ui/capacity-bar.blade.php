@props(['committed', 'used', 'currency'])

@php
    if ($currency === null) {
        throw new InvalidArgumentException('x-ui.capacity-bar requires a currency; amounts are never shown bare because USD, EUR and SYP are not convertible in this system.');
    }

    $committedF = (float) $committed;
    $usedF = (float) $used;
    $pct = $committedF > 0 ? ($usedF / $committedF) * 100 : 0;
    $isOver = $committedF > 0 && $usedF > $committedF;
    $fillClasses = $isOver ? 'bg-status-warn' : 'bg-funding';
@endphp

<div {{ $attributes->class('flex flex-col gap-1.5') }}>
    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
        <div class="flex items-baseline gap-1.5">
            <span class="type-meta text-secondary-medium dark:text-ink-muted">{{ __('ui.received') }}</span>
            <x-ui.money :amount="$usedF" :currency="$currency" />
        </div>
        <div class="flex items-baseline gap-1.5">
            <span class="type-meta text-secondary-medium dark:text-ink-muted">{{ __('common.committed') }}</span>
            <x-ui.money :amount="$committedF" :currency="$currency" />
        </div>
    </div>

    <div
        class="h-1.5 w-full overflow-hidden rounded-full bg-secondary-soft dark:bg-surface-2"
        role="progressbar"
        aria-valuemin="0"
        aria-valuemax="{{ $committedF }}"
        aria-valuenow="{{ $usedF }}"
        aria-label="{{ __('projects.funding_envelope') }}"
    >
        <div class="{{ $fillClasses }} h-full rounded-full" style="width: {{ $isOver ? 100 : max(0, min(100, $pct)) }}%"></div>
    </div>

    @if ($isOver)
        <p class="type-meta text-status-warn">{{ __('ui.over_by', ['percent' => round($pct - 100)]) }}</p>
    @endif
</div>
