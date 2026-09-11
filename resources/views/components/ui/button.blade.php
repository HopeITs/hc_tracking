@props(['tone' => 'secondary'])

@php
    $toneClasses = match ($tone) {
        'primary' => 'border-primary! bg-primary! text-white! hover:border-primary! hover:bg-primary!',
        'secondary' => 'border-secondary-light! bg-white! text-secondary-dark! hover:bg-secondary-soft! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white! dark:hover:bg-secondary-medium!',
        'ghost' => 'border-0! bg-transparent! text-secondary-dark! hover:bg-secondary-soft! dark:text-white! dark:hover:bg-secondary-medium!',
        default => throw new InvalidArgumentException("Unknown x-ui.button tone [{$tone}]."),
    };

    $fluxVariant = $tone === 'primary' ? 'primary' : 'filled';
@endphp

<flux:button :variant="$fluxVariant" {{ $attributes->class($toneClasses) }}>
    {{ $slot }}
</flux:button>
