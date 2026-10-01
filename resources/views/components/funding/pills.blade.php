@props(['label', 'pills' => []])

{{-- One value per row: a pill sets it, the active pill clears it. A null entry is skipped. --}}
<x-funding.filter-row :label="$label" {{ $attributes }}>
    @foreach (array_filter($pills) as $pill)
        @php
            $isActive = (bool) ($pill['active'] ?? false);
            $tone = $pill['tone'] ?? null;
        @endphp
        <button
            type="button"
            wire:click="{{ $pill['click'] }}"
            aria-pressed="{{ $isActive ? 'true' : 'false' }}"
            @isset($pill['test']) data-test="{{ $pill['test'] }}" @endisset
            @class([
                'inline-flex min-h-8 touch-manipulation items-center gap-1.5 rounded-full px-3 text-xs font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                'bg-secondary-dark text-white dark:bg-ink dark:text-surface-0' => $isActive,
                'text-secondary-dark hover:bg-secondary-soft dark:text-ink dark:hover:bg-surface-2' => ! $isActive,
            ])
        >
            @if ($tone !== null && ! $isActive)
                <span @class(['size-1.5 rounded-full', 'bg-primary dark:bg-status-critical' => $tone === 'critical', 'bg-status-warn' => $tone === 'warn']) aria-hidden="true"></span>
            @endif
            {{ $pill['label'] }}
            @if (array_key_exists('count', $pill))
                <span class="tabular-nums opacity-70">{{ $pill['count'] }}</span>
            @endif
        </button>
    @endforeach

    {{ $slot }}
</x-funding.filter-row>
