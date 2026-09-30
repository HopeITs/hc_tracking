@php
    /*
     * The Install HC Tracking dialog, opened by name ('install-app') from the
     * header icon and the notification settings page. The browser draws its
     * own install confirm, which no page can restyle, so this comes first:
     * it says what the app gives, and Install hands over to the browser
     * (appInstallDialog, notifications.js). It closes after the browser's
     * answer, and by itself when the prompt goes away while it is open.
     */
    $reasons = [
        ['title' => __('notifications.app.window_title'), 'text' => __('notifications.app.window_text')],
        ['title' => __('notifications.app.alerts_title'), 'text' => __('notifications.app.alerts_text')],
        ['title' => __('notifications.app.live_title'), 'text' => __('notifications.app.live_text')],
    ];
@endphp

<x-ui.modal
    size="sm"
    name="install-app"
    :title="__('notifications.app.install')"
    :description="__('notifications.app.description')"
    :cancel-label="__('notifications.app.not_now')"
    x-data="appInstallDialog"
    x-on:hc-app-install-change.window="closeWhenGone($event.detail)"
    data-app-install-modal
>
    <x-slot:mark>
        <img src="{{ asset('app-icons/icon-192.png') }}" alt="" width="40" height="40" class="mt-1 size-10 shrink-0 rounded-full" data-no-dim />
    </x-slot:mark>

    <ol class="install-thread">
        @foreach ($reasons as $reason)
            <li class="install-thread-step" data-install-reason>
                <span class="install-thread-dot" aria-hidden="true"></span>
                <p class="install-thread-title">{{ $reason['title'] }}</p>
                <p class="install-thread-text">{{ $reason['text'] }}</p>
            </li>
        @endforeach
    </ol>

    <x-slot:footerMeta>
        <span class="inline-flex items-center gap-1.5">
            <flux:icon.information-circle variant="micro" class="shrink-0 text-secondary-light" />
            {{ __('notifications.app.confirm_next') }}
        </span>
    </x-slot:footerMeta>

    <x-slot:actions>
        <x-ui.button
            type="button"
            tone="primary"
            icon="arrow-down-tray"
            x-on:click="install()"
            x-bind:disabled="installing"
            x-bind:aria-busy="installing.toString()"
            data-test="install-app-confirm"
        >
            {{ __('notifications.app.install') }}
        </x-ui.button>
    </x-slot:actions>
</x-ui.modal>
