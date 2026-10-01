@props(['label'])

{{-- Flux puts w-full on an input's wrapper; inside a filter row inputs sit inline at a compact width instead. --}}
<div {{ $attributes->class('flex flex-wrap items-center gap-x-2 gap-y-1.5 [&>[data-flux-input]]:w-32 [&>[data-flux-input]:has(input[type=date])]:w-40') }} role="group" aria-label="{{ $label }}">
    <span class="type-label w-24 shrink-0 text-secondary-medium dark:text-ink-faint">{{ $label }}</span>
    {{ $slot }}
</div>
