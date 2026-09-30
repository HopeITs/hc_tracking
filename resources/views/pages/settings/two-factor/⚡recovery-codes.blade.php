<?php

use App\Livewire\Concerns\RequiresConfirmedPassword;
use Flux\Flux;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    use RequiresConfirmedPassword;

    #[Locked]
    public array $recoveryCodes = [];

    /**
     * Mount the component. The codes are only read while the password
     * confirmation is fresh: this component can also be mounted inside a
     * Livewire update of the security page, which skips password.confirm.
     */
    public function mount(): void
    {
        if ($this->passwordRecentlyConfirmed()) {
            $this->loadRecoveryCodes();
        }
    }

    /**
     * Generate new recovery codes for the user.
     */
    public function regenerateRecoveryCodes(GenerateNewRecoveryCodes $generateNewRecoveryCodes): void
    {
        if (! $this->ensurePasswordIsConfirmed()) {
            return;
        }

        $generateNewRecoveryCodes(auth()->user());

        $this->loadRecoveryCodes();

        Flux::toast(variant: 'success', text: __('settings.recovery_codes_regenerated'));
    }

    /**
     * Load the recovery codes for the user.
     */
    private function loadRecoveryCodes(): void
    {
        $user = auth()->user();

        if ($user->hasEnabledTwoFactorAuthentication() && $user->two_factor_recovery_codes) {
            try {
                $this->recoveryCodes = json_decode(decrypt($user->two_factor_recovery_codes), true);
            } catch (Exception) {
                $this->addError('recoveryCodes', 'Failed to load recovery codes');

                $this->recoveryCodes = [];
            }
        }
    }
}; ?>

<div
    class="space-y-3 border-s-2 border-secondary-soft ps-4 dark:border-line"
    wire:cloak
    x-data="{ showRecoveryCodes: false }"
>
    <div>
        <h4 class="flex items-center gap-2 font-semibold">
            <flux:icon.lock-closed variant="micro" class="size-4 text-secondary-light dark:text-ink-muted" />
            {{ __('settings.2fa_recovery_codes') }}
        </h4>
        <p class="mt-1 text-secondary-medium dark:text-ink-muted">
            {{ __('settings.recovery_codes_let_you_regain_access_if_you_lose_your_2fa_de') }}
        </p>
    </div>

    <div class="flex flex-wrap items-center gap-2">
        <x-ui.button
            x-show="!showRecoveryCodes"
            size="sm"
            icon="eye"
            icon:variant="outline"
            @click="showRecoveryCodes = true"
            aria-expanded="false"
            aria-controls="recovery-codes-section"
            class="border"
        >
            {{ __('settings.view_recovery_codes') }}
        </x-ui.button>

        <x-ui.button
            x-show="showRecoveryCodes"
            x-cloak
            size="sm"
            icon="eye-slash"
            icon:variant="outline"
            @click="showRecoveryCodes = false"
            aria-expanded="true"
            aria-controls="recovery-codes-section"
            class="border"
        >
            {{ __('settings.hide_recovery_codes') }}
        </x-ui.button>

        @if (filled($recoveryCodes))
            <x-ui.button
                x-show="showRecoveryCodes"
                x-cloak
                tone="ghost"
                size="sm"
                icon="arrow-path"
                wire:click="regenerateRecoveryCodes"
            >
                {{ __('settings.regenerate_codes') }}
            </x-ui.button>
        @endif
    </div>

    <div
        x-show="showRecoveryCodes"
        x-cloak
        x-transition
        id="recovery-codes-section"
        x-bind:aria-hidden="!showRecoveryCodes"
    >
        <div class="space-y-3">
            @error('recoveryCodes')
                <flux:callout variant="danger" icon="x-circle" heading="{{ $message }}" />
            @enderror

            @if (filled($recoveryCodes))
                <div
                    class="grid grid-cols-2 gap-x-6 gap-y-1 rounded-lg bg-secondary-soft/40 p-4 font-mono text-sm dark:bg-white/5"
                    role="list"
                    aria-label="{{ __('settings.recovery_codes') }}"
                    dir="ltr"
                >
                    @foreach ($recoveryCodes as $code)
                        <div role="listitem" class="select-text" wire:loading.class="opacity-50 animate-pulse">{{ $code }}</div>
                    @endforeach
                </div>
                <p class="type-meta text-secondary-medium dark:text-ink-muted">
                    {{ __('settings.each_recovery_code_can_be_used_once_to_access_your_account_a') }}
                </p>
            @endif
        </div>
    </div>
</div>
