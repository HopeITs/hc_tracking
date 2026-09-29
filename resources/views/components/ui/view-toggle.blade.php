@props(['wireModel', 'active' => null, 'options' => [], 'label' => null])

<div
    data-view-toggle {{ $attributes->class('inline-flex items-center gap-0.5 rounded-lg border border-secondary-soft bg-white p-0.5 dark:border-secondary-medium dark:bg-secondary-dark') }}
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
                'inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 type-meta font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-primary',
                'bg-primary text-white' => $isActive,
                'text-secondary-medium hover:text-secondary-dark dark:text-secondary-soft dark:hover:text-white' => ! $isActive,
            ])
        >
            @if (! empty($option['icon']))
                <flux:icon :name="$option['icon']" class="size-4" />
            @endif
            {{ $option['label'] }}
        </button>
    @endforeach
</div>
