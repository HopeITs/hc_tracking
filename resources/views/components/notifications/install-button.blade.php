{{-- Opens the Install HC Tracking modal (x-notifications.install-modal) from the
     top header: the icon alone, named by its tooltip. It only shows while the
     browser holds an install prompt (window.hcApp, notifications.js), so it
     disappears once the app is installed or when opened inside it. --}}
<flux:tooltip
    :content="__('notifications.app.tooltip')"
    position="bottom"
    x-data="{ state: window.hcApp?.state() ?? 'unavailable' }"
    x-on:hc-app-install-change.window="state = $event.detail"
    x-show="state === 'available'"
    x-cloak
    class="inline-flex"
>
    <button
        type="button"
        x-on:click="$flux.modal('install-app').show()"
        aria-haspopup="dialog"
        aria-label="{{ __('notifications.app.tooltip') }}"
        class="inline-flex size-8 cursor-pointer touch-manipulation items-center justify-center rounded-full text-secondary-medium transition-colors hover:bg-white hover:text-secondary-dark hover:shadow-sm max-lg:size-11 dark:text-ink-muted dark:hover:bg-surface-3 dark:hover:text-ink dark:hover:shadow-raised"
        data-top-bar-action
        data-test="header-install-app-button"
    >
        <flux:icon.arrow-down-tray variant="outline" class="size-4.5!" />
    </button>
</flux:tooltip>
