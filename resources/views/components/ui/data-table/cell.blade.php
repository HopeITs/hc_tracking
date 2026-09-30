@props([
    'label' => null,
    'numeric' => false,
])

<td
    {{ $attributes->class([
        '@max-3xl:flex @max-3xl:items-center @max-3xl:justify-between @max-3xl:gap-3 align-middle px-3.5 py-2.5 text-secondary-dark dark:text-ink',
        '@3xl:text-end' => $numeric,
    ]) }}
    @if ($label) data-label="{{ $label }}" @endif
>
    @if ($label)
        <span class="type-label hidden shrink-0 text-secondary-medium @max-3xl:inline dark:text-ink-muted">{{ $label }}</span>
    @endif
    <span class="min-w-0 {{ $numeric ? 'tabular-nums' : '' }}">{{ $slot }}</span>
</td>
