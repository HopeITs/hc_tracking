{{-- Opens the user guideline document in a new tab; shared by the desktop and mobile top headers. --}}
<flux:button
    href="https://docs.google.com/document/d/1-cQQQrmqRirFbkwU22RL9YkpIY1_D8n8/edit"
    target="_blank"
    rel="noopener noreferrer"
    variant="ghost"
    size="sm"
    square
    class="size-9! touch-manipulation text-secondary-medium! hover:bg-secondary-soft! hover:text-secondary-dark! max-lg:size-11! dark:text-ink-muted! dark:hover:bg-white/10! dark:hover:text-ink!"
    :aria-label="__('navigation.guideline')"
    :tooltip="__('navigation.guideline')"
    tooltip:position="bottom"
    data-guideline
    data-top-bar-action
>
    <flux:icon.book-open variant="mini" />
</flux:button>
