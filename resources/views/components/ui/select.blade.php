@props(['label' => null])

<flux:select
    :label="$label"
    {{ $attributes->class('border-secondary-light! bg-white! text-secondary-dark! placeholder:text-secondary-light! dark:border-secondary-light! dark:bg-secondary-medium! dark:text-white!') }}
>
    {{ $slot }}
</flux:select>
