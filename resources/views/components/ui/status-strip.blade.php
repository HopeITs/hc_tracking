@props([
    'label',
    'segments' => [],
    'active' => null,
    'wireModel' => null,
])

@php
    $segments = collect($segments)->values();
    $total = (int) $segments->sum('count');

    $toneClasses = fn (string $tone): string => match ($tone) {
        'good' => 'bg-status-good',
        'warn' => 'bg-status-warn',
        'critical' => 'bg-primary dark:bg-status-critical',
        'funding' => 'bg-funding',
        default => 'bg-secondary-light dark:bg-ink-faint',
    };
@endphp

<<<<<<< HEAD
<div {{ $attributes->class('flex flex-col gap-2') }}>
    <div class="flex h-2.5 w-full overflow-hidden rounded-full bg-secondary-soft dark:bg-surface-2" role="img" aria-label="{{ $label }}">
=======
<div data-status-strip {{ $attributes->class('flex flex-col gap-2') }}>
    <div class="flex h-2.5 w-full overflow-hidden rounded-full bg-secondary-soft dark:bg-secondary-medium" role="img" aria-label="{{ $label }}">
>>>>>>> gitlab/security-mai
        @if ($total > 0)
            @foreach ($segments as $segment)
                @php $count = (int) ($segment['count'] ?? 0); @endphp
                @if ($count > 0)
                    <span
                        class="{{ $toneClasses($segment['tone'] ?? 'neutral') }} h-full"
                        style="width: {{ max(($count / $total) * 100, 2) }}%"
                    ></span>
                @endif
            @endforeach
        @endif
    </div>

    <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5" role="group" aria-label="{{ $label }}">
        @if ($total === 0)
            <p class="type-meta text-secondary-medium dark:text-ink-muted">{{ __('ui.no_records') }}</p>
        @else
            @foreach ($segments as $segment)
                @php
                    $key = $segment['key'] ?? '';
                    $isActive = $wireModel && $active === $key;
                    $target = $isActive ? '' : $key;
                @endphp
                @if ($wireModel)
                    <button
                        type="button"
                        wire:click="$set('{{ $wireModel }}', '{{ $target }}')"
                        aria-pressed="{{ $isActive ? 'true' : 'false' }}"
                        @class([
                            'inline-flex items-center gap-1.5 rounded-md px-1.5 py-1 type-meta transition-colors hover:bg-secondary-soft focus-visible:outline-2 focus-visible:outline-primary dark:hover:bg-surface-2',
                            'text-secondary-dark dark:text-ink' => ! $isActive,
                            'bg-secondary-soft text-secondary-dark dark:bg-surface-2 dark:text-ink' => $isActive,
                        ])
                    >
                        <span class="{{ $toneClasses($segment['tone'] ?? 'neutral') }} size-2 shrink-0 rounded-full" aria-hidden="true"></span>
                        {{ $segment['label'] ?? '' }} · <span class="tabular-nums">{{ $segment['count'] ?? 0 }}</span>
                    </button>
                @else
                    <span class="inline-flex items-center gap-1.5 type-meta text-secondary-dark dark:text-ink">
                        <span class="{{ $toneClasses($segment['tone'] ?? 'neutral') }} size-2 shrink-0 rounded-full" aria-hidden="true"></span>
                        {{ $segment['label'] ?? '' }} · <span class="tabular-nums">{{ $segment['count'] ?? 0 }}</span>
                    </span>
                @endif
            @endforeach
        @endif
    </div>
</div>
