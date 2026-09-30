{{-- Opens the user guideline document in a new tab; shared by the desktop and mobile top headers. --}}
<flux:button
    href="https://docs.google.com/document/d/1-cQQQrmqRirFbkwU22RL9YkpIY1_D8n8/edit"
    target="_blank"
    rel="noopener noreferrer"
    variant="ghost"
    size="sm"
    square
    class="size-8! touch-manipulation text-secondary-medium! hover:bg-white! hover:text-secondary-dark! hover:shadow-sm max-lg:size-11! dark:text-ink-muted! dark:hover:bg-surface-3! dark:hover:text-ink! dark:hover:shadow-raised"
    :aria-label="__('navigation.guideline')"
    :tooltip="__('navigation.guideline')"
    tooltip:position="bottom"
    data-guideline
    data-top-bar-action
>
    <flux:icon.book-open variant="outline" class="size-4.5!" />
</flux:button>
