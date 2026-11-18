{{--
    The line under the status buttons while one is pressed: "Showing 2 of 12
    … · Show all". The link runs `action` (the page's own toggle, called with
    the pressed key, so it clears the button). Pair it with x-ui.focus-clear-mark.

    Used by indicator-library, admin/narrative-questions, admin/risk-library,
    indicators and report-templates.
--}}
@props(['text', 'action', 'label'])

<p {{ $attributes->class('type-meta flex flex-wrap items-center gap-x-2 text-secondary-medium dark:text-ink-muted') }} aria-live="polite">
    <span>{{ $text }}</span>
    <button type="button" wire:click="{{ $action }}" class="cursor-pointer font-semibold text-secondary-dark underline underline-offset-2 dark:text-ink" data-test="focus-show-all">{{ $label }}</button>
</p>
