<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('titles.notification_settings')] class extends Component {
    public bool $popups = false;

    public function mount(): void
    {
        $this->popups = auth()->user()->notification_popups;
    }

    public function updatedPopups(): void
    {
        auth()->user()->forceFill(['notification_popups' => $this->popups])->save();
    }
}; ?>

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-6 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="notification-settings-heading">
    <x-ui.page-header heading-id="notification-settings-heading" :title="__('notifications.settings_link')" />

    <div class="max-w-2xl">
        <div
            class="grid gap-2"
            x-data="{
                permission: 'Notification' in window ? Notification.permission : 'unsupported',
                testing: false,
                async ask() {
                    if (this.permission === 'default') {
                        this.permission = await Notification.requestPermission();
                    }
                },
                async testPopup() {
                    await this.ask();

                    if (this.permission !== 'granted') {
                        return;
                    }

                    this.testing = true;
                    window.setTimeout(() => {
                        new Notification(@js(__('notifications.modules.system')), { body: @js(__('notifications.test_message')) });
                        this.testing = false;
                    }, 5000);
                },
            }"
            data-popup-preference
        >
            <label class="flex items-start gap-3">
                <input
                    type="checkbox"
                    wire:model.live="popups"
                    x-on:change="document.body.dataset.notificationPopups = $event.target.checked ? 'on' : 'off'; if ($event.target.checked) { ask() }"
                    class="mt-1 size-4 accent-primary"
                    data-test="notification-popups-toggle"
                />
                <span>
                    <span class="block text-sm font-semibold">{{ __('notifications.popups_label') }}</span>
                    <span class="block text-sm text-secondary-medium dark:text-secondary-soft">{{ __('notifications.popups_help') }}</span>
                </span>
            </label>
            <p x-show="permission === 'denied'" x-cloak class="text-sm text-status-critical">{{ __('notifications.popups_blocked') }}</p>
            <p x-show="permission === 'unsupported'" x-cloak class="text-sm text-secondary-medium dark:text-secondary-soft">{{ __('notifications.popups_unsupported') }}</p>

            <div x-show="permission !== 'unsupported' && permission !== 'denied'" class="mt-2 flex flex-wrap items-center gap-3 ps-7">
                <x-ui.button tone="ghost" size="sm" icon="bell-alert" x-on:click="testPopup()" x-bind:disabled="testing" data-test="notification-popup-test">{{ __('notifications.popup_test') }}</x-ui.button>
                <span x-show="testing" x-cloak class="text-sm text-secondary-medium dark:text-secondary-soft" role="status">{{ __('notifications.popup_test_waiting') }}</span>
            </div>
        </div>
    </div>
</section>
