@props(['label' => null])

<flux:select
    :label="$label"
    {{ $attributes->class('border-secondary-light! bg-white! text-secondary-dark! placeholder:text-secondary-light! dark:border-field-line! dark:bg-surface-2! dark:text-ink!') }}
>
    {{ $slot }}
</flux:select>
