{{-- Opens the external bug-report form in a new tab; shared by the desktop and mobile top headers. --}}
<flux:button
    href="https://forms.gle/5QfGGarkeejAe3HS6"
    target="_blank"
    rel="noopener noreferrer"
    variant="ghost"
    size="sm"
    square
    class="size-8! touch-manipulation text-secondary-medium! hover:bg-white! hover:text-secondary-dark! hover:shadow-sm max-lg:size-11! dark:text-ink-muted! dark:hover:bg-surface-3! dark:hover:text-ink! dark:hover:shadow-raised"
    :aria-label="__('navigation.report_a_problem')"
    :tooltip="__('navigation.report_a_problem')"
    tooltip:position="bottom"
    data-report-bug
    data-top-bar-action
>
    <flux:icon.chat-bubble-exclamation variant="outline" class="size-4.5!" />
</flux:button>
