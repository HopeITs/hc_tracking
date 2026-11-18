{{--
    The small ✕ on a pressed status button (the big-number buttons above a
    list). Pressing the button again shows everything; hovering the ✕ says so.
    Put it last inside the pressed button only. Pair it with x-ui.focus-showing.

    Used by indicator-library, admin/narrative-questions, admin/risk-library,
    indicators and report-templates.
--}}
@props(['label'])

<span {{ $attributes->class('ms-auto inline-flex size-6 shrink-0 items-center justify-center rounded-full text-secondary-medium dark:text-ink-muted') }} title="{{ $label }}">
    <flux:icon.x-mark variant="micro" class="size-4" aria-hidden="true" />
    <span class="sr-only">{{ $label }}</span>
</span>
