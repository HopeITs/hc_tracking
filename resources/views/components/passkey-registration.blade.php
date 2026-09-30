@assets
@vite('resources/js/passkeys.js')
@endassets

<div
    x-data="{
        supported: false,
        showForm: false,
        name: '',
        loading: false,
        error: null,
        updateSupport() {
            this.supported = Boolean(window.Passkeys?.isSupported());
        },
        getDefaultPasskeyName() {
            const ua = navigator.userAgent;

            const browser = [
                { pattern: /Edg|Edge/, name: 'Edge' },
                { pattern: /OPR|Opera|OPiOS/, name: 'Opera' },
                { pattern: /Firefox|FxiOS/, name: 'Firefox' },
                { pattern: /Chrome|CriOS/, name: 'Chrome' },
                { pattern: /Safari/, name: 'Safari' },
            ].find(({ pattern }) => pattern.test(ua))?.name;

            const os = [
                { pattern: /iPhone/, name: 'iPhone' },
                { pattern: /iPad|Macintosh(?=.*Mobile)/, name: 'iPad' },
                { pattern: /Android/, name: 'Android' },
                { pattern: /Mac/, name: 'Mac' },
                { pattern: /Windows/, name: 'Windows' },
            ].find(({ pattern }) => pattern.test(ua))?.name;

            return [browser, os].filter(Boolean).join(' on ') || '';
        },
        init() {
            this.name = this.getDefaultPasskeyName();
            this.updateSupport();

            window.addEventListener('passkeys:ready', () => this.updateSupport(), { once: true });
        },
        async register() {
            if (!this.name.trim()) return;

            this.loading = true;
            this.error = null;

            try {
                await window.Passkeys.register({ name: this.name });
                this.name = '';
                this.showForm = false;
                await $wire.loadPasskeys();
            } catch (e) {
                if (e.constructor?.name !== 'UserCancelledError') {
                    this.error = e.message;
                }
            } finally {
                this.loading = false;
            }
        },
        cancel() {
            this.showForm = false;
            this.name = '';
            this.error = null;
        },
    }"
>
    <template x-if="!supported">
        <p class="text-sm text-secondary-medium dark:text-ink-muted">{{ __('ui.passkeys_are_not_supported_in_this_browser') }}</p>
    </template>

    <template x-if="supported && !showForm">
        <div>
            <x-ui.button
                size="sm"
                icon="plus"
                x-on:click="showForm = true"
                class="border"
            >
                {{ __('ui.add_passkey') }}
            </x-ui.button>
        </div>
    </template>

    <template x-if="supported && showForm">
        <div class="space-y-4 border-s-2 border-primary ps-4">
            <flux:input
                label="{{ __('ui.passkey_name') }}"
                x-model="name"
                placeholder="{{ __('ui.e_g_macbook_pro_iphone') }}"
                x-on:keydown.enter.prevent="register()"
                x-ref="passkeyNameInput"
                x-init="$nextTick(() => $refs.passkeyNameInput?.focus())"
            />
            <flux:text class="mt-1!">{{ __('ui.give_this_passkey_a_name_to_help_you_identify_it_later') }}</flux:text>

            <p x-show="error" x-text="error" x-cloak class="text-sm text-primary dark:text-ink"></p>

            <div class="flex gap-2">
                <flux:button
                    variant="primary"
                    size="sm"
                    x-on:click="register()"
                    x-bind:disabled="loading || !name.trim()"
                >
                    <span x-show="!loading">{{ __('ui.register_passkey') }}</span>
                    <span x-show="loading" x-cloak>{{ __('ui.registering') }}</span>
                </flux:button>
                <flux:button
                    variant="ghost"
                    size="sm"
                    x-on:click="cancel()"
                >
                    {{ __('common.cancel') }}
                </flux:button>
            </div>
        </div>
    </template>
</div>
