{{-- Installs HC Tracking as a desktop app from the top header. It only shows
     while the browser holds an install prompt (window.hcApp, notifications.js),
     so it disappears once the app is installed or when opened inside it. --}}
<button
    type="button"
    x-data="{
        state: window.hcApp?.state() ?? 'unavailable',
        async install() {
            await window.hcApp?.install();
            this.state = window.hcApp?.state() ?? this.state;
        },
    }"
    x-on:hc-app-install-change.window="state = $event.detail"
    x-show="state === 'available'"
    x-cloak
    x-on:click="install()"
    aria-label="{{ __('notifications.app.install') }}"
    class="inline-flex h-9 cursor-pointer touch-manipulation items-center justify-center gap-1.5 rounded-lg px-2.5 text-sm font-semibold text-secondary-medium transition-colors hover:bg-secondary-soft hover:text-secondary-dark max-lg:size-11 max-lg:px-0 dark:text-secondary-soft dark:hover:bg-white/10 dark:hover:text-white"
    data-test="header-install-app-button"
>
    <flux:icon.arrow-down-tray variant="mini" />
    <span class="max-lg:hidden">{{ __('notifications.app.install') }}</span>
</button>
