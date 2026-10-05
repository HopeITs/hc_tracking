@props(['tone' => 'secondary'])

@php
    $toneClasses = match ($tone) {
        'primary' => 'border-primary! bg-primary! text-white! hover:border-primary! hover:bg-primary! dark:border-brand! dark:bg-brand! dark:hover:border-brand-hover! dark:hover:bg-brand-hover!',
        'secondary' => 'border-secondary-light! bg-white! text-secondary-dark! hover:bg-secondary-soft! dark:border-line-strong! dark:bg-surface-2! dark:text-ink! dark:hover:bg-surface-3!',
        'ghost' => 'border-0! bg-transparent! text-secondary-dark! hover:bg-secondary-soft! dark:text-ink! dark:hover:bg-white/[0.06]!',
        'neutral' => 'border-secondary-dark! bg-secondary-dark! text-white! hover:border-secondary-medium! hover:bg-secondary-medium! dark:border-ink! dark:bg-ink! dark:text-surface-0! dark:hover:bg-ink/85!',
        // Page header actions (Export, Filters, Cancel): the look comes from .page-action in flux-overrides.css.
        'action' => 'page-action',
        default => throw new InvalidArgumentException("Unknown x-ui.button tone [{$tone}]."),
    };

    $fluxVariant = $tone === 'primary' ? 'primary' : 'filled';
@endphp

<flux:button :variant="$fluxVariant" {{ $attributes->class($toneClasses) }}>
    {{ $slot }}
</flux:button>
