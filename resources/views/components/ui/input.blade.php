@props(['label' => null])

<flux:input
    :label="$label"
    {{ $attributes->class('border-secondary-light! bg-white! text-secondary-dark! placeholder:text-secondary-light! dark:border-field-line! dark:bg-surface-2! dark:text-ink!') }}
>
    {{ $slot }}
</flux:input>
