{{-- Opens the external bug-report form in a new tab; shared by the desktop and mobile top headers. --}}
<flux:button
    href="https://forms.gle/5QfGGarkeejAe3HS6"
    target="_blank"
    rel="noopener noreferrer"
    variant="ghost"
    size="sm"
    square
    class="size-9! touch-manipulation text-secondary-medium! hover:bg-secondary-soft! hover:text-secondary-dark! max-lg:size-11! dark:text-ink-muted! dark:hover:bg-white/10! dark:hover:text-ink!"
    :aria-label="__('navigation.report_a_problem')"
    :tooltip="__('navigation.report_a_problem')"
    tooltip:position="bottom"
    data-report-bug
    data-top-bar-action
>
    <flux:icon.chat-bubble-exclamation variant="mini" />
</flux:button>
