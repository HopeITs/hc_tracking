@props(['wireModel', 'active' => null, 'options' => [], 'label' => null])

<div
    data-view-toggle {{ $attributes->class('inline-flex h-10 items-center gap-0.5 rounded-lg border border-secondary-soft bg-white p-1 dark:border-line dark:bg-surface-1') }}
    role="radiogroup"
    @if ($label !== null) aria-label="{{ $label }}" @endif
>
    @foreach ($options as $option)
        @php $isActive = $active === $option['key']; @endphp
        <button
            type="button"
            role="radio"
            aria-checked="{{ $isActive ? 'true' : 'false' }}"
            wire:click="$set('{{ $wireModel }}', '{{ $option['key'] }}')"
            @class([
                'inline-flex h-full items-center gap-1.5 rounded-md px-3 text-sm font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-primary',
                'bg-secondary-dark text-white dark:bg-ink dark:text-surface-0' => $isActive,
                'text-secondary-medium hover:bg-secondary-soft/60 hover:text-secondary-dark dark:text-ink-muted dark:hover:bg-surface-2 dark:hover:text-ink' => ! $isActive,
            ])
        >
            @if (! empty($option['icon']))
                <flux:icon :name="$option['icon']" class="size-4" />
            @endif
            {{ $option['label'] }}
        </button>
    @endforeach
</div>
