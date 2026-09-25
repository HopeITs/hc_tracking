<?php

use App\Models\NotificationMute;
use App\Models\Permission;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('titles.notification_settings')] class extends Component {
    public bool $popups = false;

    /** @var list<string> */
    public array $muted = [];

    public function mount(): void
    {
        $this->popups = auth()->user()->notification_popups;
        $this->muted = $this->mutedModules();
    }

    public function updatedPopups(): void
    {
        auth()->user()->forceFill(['notification_popups' => $this->popups])->save();
    }

    /**
     * Modules I receive notifications about, and so may mute.
     *
     * @return list<string>
     */
    #[Computed]
    public function mutableModules(): array
    {
        $user = auth()->user();

        return array_values(array_filter(array_keys(config('notifications.modules')), function (string $module) use ($user): bool {
            $permission = config("notifications.view_permissions.{$module}", "{$module}.view");

            return Permission::query()->where('name', $permission)->exists() && $user->can($permission);
        }));
    }

    public function toggleMute(string $module): void
    {
        if (! in_array($module, $this->mutableModules, true)) {
            return;
        }

        $mute = NotificationMute::query()->where('user_id', auth()->id())->where('module', $module)->first();

        $muting = $mute === null;

        $muting
            ? NotificationMute::query()->create(['user_id' => auth()->id(), 'module' => $module])
            : $mute->delete();

        $this->muted = $this->mutedModules();

        Flux::toast(
            variant: 'success',
            text: $muting ? __('notifications.module_muted') : __('notifications.module_unmuted'),
        );
    }

    /**
     * @return list<string>
     */
    private function mutedModules(): array
    {
        return NotificationMute::query()->where('user_id', auth()->id())->orderBy('module')->pluck('module')->all();
    }
}; ?>

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-6 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="notification-settings-heading">
    <x-ui.page-header heading-id="notification-settings-heading" :title="__('notifications.settings_link')" />

    <div class="grid max-w-2xl gap-10">
        {{-- The installable app: its own window, Start-menu entry and branded notifications. --}}
        <section
            aria-labelledby="desktop-app-heading"
            class="grid gap-3"
            x-data="{
                state: window.hcApp?.state() ?? 'unavailable',
                init() {
                    window.addEventListener('hc-app-install-change', (event) => (this.state = event.detail));
                },
            }"
            data-desktop-app
        >
            <div class="flex items-start gap-4">
                <img src="{{ asset('app-icons/icon-192.png') }}" alt="" width="48" height="48" class="size-12 shrink-0 rounded-full" />
                <div class="min-w-0">
                    <h2 id="desktop-app-heading" class="text-base font-bold">{{ __('notifications.app.heading') }}</h2>
                    <p class="text-sm text-secondary-medium dark:text-secondary-soft">{{ __('notifications.app.help') }}</p>
                </div>
            </div>

            <div class="ps-16">
                <p x-show="state === 'installed'" x-cloak class="inline-flex items-center gap-2 text-sm font-semibold text-status-good" data-app-installed>
                    <flux:icon.check-circle variant="mini" />
                    {{ __('notifications.app.installed') }}
                </p>

                <x-ui.button x-show="state === 'available'" x-cloak tone="primary" size="sm" icon="arrow-down-tray" x-on:click="$flux.modal('install-app').show()" aria-haspopup="dialog" data-test="install-app-button">
                    {{ __('notifications.app.install') }}
                </x-ui.button>

                <p x-show="state === 'unavailable'" class="text-sm text-secondary-medium dark:text-secondary-soft" data-app-install-hint>{{ __('notifications.app.manual') }}</p>
            </div>
        </section>

        {{-- Desktop popups for important and critical notifications. --}}
        <section
            aria-labelledby="desktop-popups-heading"
            class="grid gap-2 border-t border-secondary-soft pt-8 dark:border-secondary-medium"
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
                    window.setTimeout(async () => {
                        await window.hcShowDesktopPopup?.({
                            id: 'test-' + Date.now(),
                            module_label: @js(__('notifications.modules.system')),
                            text: @js(__('notifications.test_message')),
                            priority: 'important',
                            url: @js(route('notification-settings.edit')),
                            read_url: null,
                            icon: @js(app(\App\Notifications\NotificationPresenter::class)->desktopIcon('system', 'important')),
                            image: @js(app(\App\Notifications\NotificationPresenter::class)->desktopBanner('system', 'important', app()->getLocale())),
                            created_at: new Date().toISOString(),
                        });
                        this.testing = false;
                    }, 5000);
                },
            }"
            data-popup-preference
        >
            <h2 id="desktop-popups-heading" class="sr-only">{{ __('notifications.popups_label') }}</h2>
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
            <p x-show="permission === 'denied'" x-cloak class="ps-7 text-sm text-status-critical">{{ __('notifications.popups_blocked') }}</p>
            <p x-show="permission === 'unsupported'" x-cloak class="ps-7 text-sm text-secondary-medium dark:text-secondary-soft">{{ __('notifications.popups_unsupported') }}</p>

            <div x-show="permission !== 'unsupported' && permission !== 'denied'" class="mt-2 flex flex-wrap items-center gap-3 ps-7">
                <x-ui.button tone="ghost" size="sm" icon="bell-alert" x-on:click="testPopup()" x-bind:disabled="testing" data-test="notification-popup-test">{{ __('notifications.popup_test') }}</x-ui.button>
                <span x-show="testing" x-cloak class="text-sm text-secondary-medium dark:text-secondary-soft" role="status">{{ __('notifications.popup_test_waiting') }}</span>
            </div>
        </section>

        {{-- Module mutes: team-wide updates only; anything addressed to me always arrives. --}}
        <section aria-labelledby="module-mutes-heading" class="grid gap-2 border-t border-secondary-soft pt-8 dark:border-secondary-medium" data-module-mutes>
            <h2 id="module-mutes-heading" class="text-base font-bold">{{ __('notifications.mutes_label') }}</h2>
            <p class="text-sm text-secondary-medium dark:text-secondary-soft">{{ __('notifications.mutes_help') }}</p>

            <ul class="mt-2 divide-y divide-secondary-soft dark:divide-secondary-medium">
                @foreach ($this->mutableModules as $module)
                    <li class="flex items-center justify-between gap-3 py-2.5" wire:key="mute-{{ $module }}">
                        <span @class(['text-sm font-medium', 'text-secondary-light line-through' => in_array($module, $muted, true)])>{{ app(\App\Notifications\NotificationPresenter::class)->moduleLabel($module) }}</span>
                        <label class="inline-flex cursor-pointer items-center gap-2 text-sm text-secondary-medium dark:text-secondary-soft">
                            <input type="checkbox" wire:click="toggleMute('{{ $module }}')" @checked(in_array($module, $muted, true)) class="size-4 accent-primary" data-mute-toggle="{{ $module }}" />
                            {{ __('notifications.muted') }}
                        </label>
                    </li>
                @endforeach
            </ul>
        </section>
    </div>
</section>
