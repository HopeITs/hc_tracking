<?php

use App\Actions\Fortify\TrustedDevices;
use App\Http\Middleware\EnsureTwoFactorIsEnabled;
use App\Http\UserAgent;
use App\Livewire\Concerns\RequiresConfirmedPassword;
use App\Models\TrustedDevice;
use App\Models\User;
use Flux\Flux;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Auth\SessionGuard;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Laravel\Passkeys\Actions\DeletePasskey;
use Livewire\Attributes\On;

new #[Title('titles.security_settings')] class extends Component {
    use RequiresConfirmedPassword;

    #[Locked]
    public bool $canManageTwoFactor;

    #[Locked]
    public bool $twoFactorEnabled;

    #[Locked]
    public bool $requiresConfirmation;

    /**
     * Whether this person's role must keep two-factor authentication on
     * (see EnsureTwoFactorIsEnabled).
     */
    #[Locked]
    public bool $twoFactorRequired = false;

    /**
     * Whether each sign-in asks for a code emailed to the user
     * (see App\Actions\Fortify\RedirectIfTwoFactorRequired).
     */
    #[Locked]
    public bool $emailTwoFactorEnabled = false;

    /**
     * Whether the user's role makes the emailed code compulsory.
     */
    #[Locked]
    public bool $emailTwoFactorRequired = false;

    #[Locked]
    public bool $canManageSignedInDevices;

    #[Locked]
    public bool $canManagePasskeys;

    #[Locked]
    public array $passkeys = [];

    public bool $showDeleteModal = false;

    #[Locked]
    public ?int $deletingPasskeyId = null;

    #[Locked]
    public string $deletingPasskeyName = '';

    /**
     * Turn the emailed sign-in code on or off for the signed-in user.
     */
    public function setEmailTwoFactor(bool $enabled): void
    {
        if (! $enabled && EnsureTwoFactorIsEnabled::emailCodeRequiredFor(auth()->user())) {
            $this->emailTwoFactorRequired = true;

            Flux::toast(variant: 'danger', text: __('settings.email_2fa_required'));

            return;
        }

        if (! $this->ensurePasswordIsConfirmed()) {
            return;
        }

        auth()->user()->forceFill(['email_two_factor_enabled' => $enabled])->save();

        $this->emailTwoFactorEnabled = $enabled;

        Flux::toast(variant: 'success', text: __($enabled ? 'settings.email_2fa_enabled' : 'settings.email_2fa_disabled'));
    }

    /**
     * Mount the component.
     */
    public function mount(DisableTwoFactorAuthentication $disableTwoFactorAuthentication): void
    {
        $this->canManageTwoFactor = Features::canManageTwoFactorAuthentication();

        if ($this->canManageTwoFactor) {
            if (Fortify::confirmsTwoFactorAuthentication() && is_null(auth()->user()->two_factor_confirmed_at)) {
                $disableTwoFactorAuthentication(auth()->user());
            }

            $this->twoFactorEnabled = auth()->user()->hasEnabledTwoFactorAuthentication();
            $this->requiresConfirmation = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
            $this->twoFactorRequired = EnsureTwoFactorIsEnabled::appIsOnlyFactorFor(auth()->user());
        }

        $this->emailTwoFactorEnabled = auth()->user()->usesEmailTwoFactor();
        $this->emailTwoFactorRequired = EnsureTwoFactorIsEnabled::emailCodeRequiredFor(auth()->user());

        $this->canManageSignedInDevices = config('session.driver') === 'database';

        $this->canManagePasskeys = Features::canManagePasskeys();

        if ($this->canManagePasskeys) {
            $this->loadPasskeys();
        }
    }


    /**
     * Load the user's passkeys.
     */
    public function loadPasskeys(): void
    {
        $this->passkeys = auth()->user()->passkeys()
            ->select(['id', 'name', 'credential', 'created_at', 'last_used_at'])
            ->latest()
            ->get()
            ->map(fn ($passkey) => [
                'id' => $passkey->id,
                'name' => $passkey->name,
                'authenticator' => $passkey->authenticator,
                'created_at_diff' => $passkey->created_at->diffForHumans(),
                'last_used_at_diff' => $passkey->last_used_at?->diffForHumans(),
            ])
            ->toArray();
    }

    /**
     * Show the delete confirmation modal.
     */
    public function confirmDelete(int $passkeyId): void
    {
        if (! $this->ensurePasswordIsConfirmed()) {
            return;
        }

        $passkey = auth()->user()->passkeys()->findOrFail($passkeyId);

        $this->deletingPasskeyId = $passkey->id;
        $this->deletingPasskeyName = $passkey->name;
        $this->showDeleteModal = true;
    }

    /**
     * Delete the passkey.
     */
    public function deletePasskey(DeletePasskey $deletePasskey): void
    {
        if (! $this->deletingPasskeyId || ! $this->ensurePasswordIsConfirmed()) {
            return;
        }

        $passkey = auth()->user()->passkeys()->findOrFail($this->deletingPasskeyId);

        $deletePasskey(auth()->user(), $passkey);

        $this->closeDeleteModal();
        $this->loadPasskeys();

        Flux::toast(variant: 'success', text: __('settings.passkey_removed'));
    }

    /**
     * Close the delete confirmation modal.
     */
    public function closeDeleteModal(): void
    {
        $this->showDeleteModal = false;
        $this->deletingPasskeyId = null;
        $this->deletingPasskeyName = '';
    }

    /**
     * Handle the two-factor authentication enabled event.
     */
    #[On('two-factor-enabled')]
    public function onTwoFactorEnabled(): void
    {
        $this->twoFactorEnabled = auth()->user()->fresh()->hasEnabledTwoFactorAuthentication();
    }

    /**
     * Disable two-factor authentication for the user, unless it is the only
     * second factor their role can use. Recovery codes can still be regenerated either way.
     */
    public function disable(DisableTwoFactorAuthentication $disableTwoFactorAuthentication): void
    {
        if (EnsureTwoFactorIsEnabled::appIsOnlyFactorFor(auth()->user())) {
            $this->twoFactorRequired = true;

            Flux::toast(variant: 'danger', text: __('settings.two_factor_required_cannot_disable'));

            return;
        }

        if (! $this->ensurePasswordIsConfirmed()) {
            return;
        }

        $disableTwoFactorAuthentication(auth()->user());

        $this->twoFactorEnabled = false;

        Flux::toast(variant: 'success', text: __('settings.two_factor_authentication_disabled'));
    }

    /**
     * What a new sign-in asks for after the password: the authenticator app
     * takes priority over the emailed code (see RedirectIfTwoFactorRequired).
     *
     * @return 'app'|'email'|'password'
     */
    #[Computed]
    public function signInCheck(): string
    {
        return match (true) {
            $this->canManageTwoFactor && $this->twoFactorEnabled => 'app',
            $this->emailTwoFactorEnabled => 'email',
            default => 'password',
        };
    }

    /**
     * The browsers signed in to this account, newest first. Rows past the
     * session lifetime are left out, as they can no longer be used; session
     * ids and payloads never leave the server.
     *
     * @return list<array{label: string, mobile: bool, ip_address: ?string, last_active: string, current: bool}>
     */
    #[Computed]
    public function signedInDevices(): array
    {
        if (! $this->canManageSignedInDevices) {
            return [];
        }

        return $this->sessionRows()
            ->where('last_activity', '>=', now()->subMinutes((int) config('session.lifetime'))->getTimestamp())
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(function (object $session): array {
                $userAgent = UserAgent::parse($session->user_agent);

                return [
                    'label' => $userAgent->label(),
                    'mobile' => in_array($userAgent->platform, ['Android', 'iOS', 'iPadOS'], true),
                    'ip_address' => $session->ip_address,
                    'last_active' => Date::createFromTimestamp($session->last_activity)->diffForHumans(),
                    'current' => $session->id === session()->getId(),
                ];
            })
            ->all();
    }

    /**
     * Sign this account out everywhere but here: the other session rows go,
     * and a new remember token stops remember-me cookies from signing those
     * devices straight back in. OtherDeviceLogout writes the auth log line
     * and tells those devices' open tabs to drop their live websocket
     * (BroadcastSessionEnded).
     */
    public function signOutOtherDevices(): void
    {
        if (! $this->canManageSignedInDevices || ! $this->ensurePasswordIsConfirmed()) {
            return;
        }

        $this->sessionRows()->where('id', '!=', session()->getId())->delete();

        $this->rotateRememberToken(auth()->user());

        TrustedDevices::forgetOthers(request(), auth()->user());

        event(new OtherDeviceLogout('web', auth()->user()));

        unset($this->signedInDevices, $this->trustedDevices);

        Flux::toast(variant: 'success', text: __('settings.other_devices_signed_out'));
    }

    /**
     * Browsers that skip the second sign-in step, newest use first.
     *
     * @return list<array{label: string, last_used: string, expires: string, current: bool}>
     */
    #[Computed]
    public function trustedDevices(): array
    {
        $currentId = TrustedDevices::current(request(), auth()->user())?->getKey();

        return auth()->user()->trustedDevices()
            ->where('expires_at', '>', now())
            ->latest('last_used_at')
            ->get()
            ->map(fn (TrustedDevice $device): array => [
                'label' => (new UserAgent(...explode(' on ', $device->label, 2)))->label(),
                'last_used' => ($device->last_used_at ?? $device->created_at)->diffForHumans(),
                'expires' => $device->expires_at->translatedFormat('j M Y'),
                'current' => $device->getKey() === $currentId,
            ])
            ->all();
    }

    /**
     * Make every browser, this one included, ask for the second step again.
     */
    public function forgetTrustedDevices(): void
    {
        if (! $this->ensurePasswordIsConfirmed()) {
            return;
        }

        TrustedDevices::forgetAll(auth()->user());

        unset($this->trustedDevices);

        Flux::toast(variant: 'success', text: __('settings.trusted_devices_forgotten'));
    }

    /**
     * This account's rows in the session table.
     */
    private function sessionRows(): Builder
    {
        return DB::connection(config('session.connection'))
            ->table(config('session.table'))
            ->where('user_id', auth()->id());
    }

    /**
     * Give the account a new remember token. When this request came with a
     * remember-me cookie, this device gets a fresh one for the new token,
     * built as SessionGuard::queueRecallerCookie() builds it (for the lifetime
     * AuthManager gives the guard) but without logging in again, which would
     * fire a Login event.
     */
    private function rotateRememberToken(User $user): void
    {
        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');

        $guard->getProvider()->updateRememberToken($user, $token = Str::random(60));

        if (! request()->cookies->has($guard->getRecallerName())) {
            return;
        }

        $cookies = $guard->getCookieJar();

        $cookies->queue($cookies->make(
            $guard->getRecallerName(),
            $user->getAuthIdentifier().'|'.$token.'|'.$guard->hashPasswordForCookie($user->getAuthPassword()),
            (int) config('auth.guards.web.remember', 576000),
        ));
    }
}; ?>

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-6 bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink" aria-labelledby="security-heading">
    @php
        $signInCheck = $this->signInCheck;
        $appStepAvailable = $canManageTwoFactor ?? false;
        [$protectionHeading, $protectionDetail] = match ($signInCheck) {
            'app' => [__('settings.protection_app'), __('settings.protection_app_detail')],
            'email' => [__('settings.protection_email'), __('settings.protection_email_detail')],
            default => [__('settings.protection_password'), __('settings.protection_password_detail')],
        };
    @endphp

    <x-ui.page-header heading-id="security-heading" :title="__('common.security')" />

    <div class="grid max-w-2xl gap-12">
        {{-- Sign-in protection: what a new sign-in asks for, then each step as a dot on one thread. --}}
        <section aria-labelledby="sign-in-protection-heading" class="grid gap-8">
            <div class="grid gap-2" data-test="sign-in-protection" data-sign-in-check="{{ $signInCheck }}">
                <h2 id="sign-in-protection-heading" class="type-label uppercase text-secondary-light dark:text-ink-muted">
                    {{ __('settings.sign_in_protection') }}
                </h2>
                <p class="flex items-center gap-3 text-3xl font-bold leading-tight tracking-tight @3xl/directory:text-4xl">
                    <span
                        aria-hidden="true"
                        @class([
                            'size-3 shrink-0 rounded-full',
                            'bg-status-good' => $signInCheck !== 'password',
                            'bg-status-warn' => $signInCheck === 'password',
                        ])
                    ></span>
                    {{ $protectionHeading }}
                </p>
                <p class="max-w-xl text-secondary-medium dark:text-ink-muted">{{ $protectionDetail }}</p>
            </div>

            @if ($appStepAvailable && $twoFactorRequired && ! $twoFactorEnabled)
                <flux:callout variant="warning" icon="shield-exclamation" data-test="two-factor-required-notice">
                    <flux:callout.heading>{{ __('settings.two_factor_required_heading') }}</flux:callout.heading>
                    <flux:callout.text>{{ __('settings.two_factor_required_explanation') }}</flux:callout.text>
                </flux:callout>
            @endif

            <ol class="grid" aria-label="{{ __('settings.sign_in_steps') }}" wire:cloak>
                <x-security.step
                    :title="__('common.password')"
                    :state="__('settings.state_always_on')"
                    :on="true"
                    data-test="hr-password-notice"
                >
                    <x-slot:description>{{ __('settings.your_password_is_managed_in_the_hr_system_change_it_there_th') }}</x-slot:description>
                </x-security.step>

                <x-security.step
                    :title="__('settings.email_2fa_heading')"
                    :state="__($emailTwoFactorRequired ? 'settings.state_required' : ($emailTwoFactorEnabled ? 'settings.state_on' : 'settings.state_off'))"
                    :on="$emailTwoFactorEnabled"
                    :last="! $appStepAvailable"
                    data-test="email-two-factor"
                >
                    <x-slot:description>{{ __('settings.email_2fa_description') }}</x-slot:description>

                    @unless ($emailTwoFactorRequired)
                        <x-slot:actions>
                            @if ($emailTwoFactorEnabled)
                                <x-ui.button size="sm" wire:click="setEmailTwoFactor(false)" data-test="email-2fa-disable" class="border">
                                    {{ __('settings.email_2fa_disable') }}
                                </x-ui.button>
                            @else
                                <x-ui.button :tone="$signInCheck === 'password' ? 'primary' : 'secondary'" size="sm" wire:click="setEmailTwoFactor(true)" data-test="email-2fa-enable" class="border">
                                    {{ __('settings.email_2fa_enable') }}
                                </x-ui.button>
                            @endif
                        </x-slot:actions>
                    @endunless

                    @if ($emailTwoFactorRequired)
                        <p class="text-secondary-medium dark:text-ink-muted" data-test="email-2fa-required">{{ __('settings.email_2fa_required') }}</p>
                    @endif

                    @if ($emailTwoFactorEnabled && $signInCheck === 'app')
                        <p class="text-secondary-medium dark:text-ink-muted">{{ __('settings.email_2fa_app_takes_priority') }}</p>
                    @endif
                </x-security.step>

                @if ($appStepAvailable)
                    <x-security.step
                        :title="__('settings.app_2fa_heading')"
                        :state="__($twoFactorEnabled ? 'settings.state_on' : ($twoFactorRequired ? 'settings.state_required' : 'settings.state_off'))"
                        :on="$twoFactorEnabled"
                        :last="true"
                        data-test="app-two-factor"
                    >
                        <x-slot:description>
                            {{ $twoFactorEnabled
                                ? __('settings.you_will_be_prompted_for_a_secure_random_pin_during_login_wh')
                                : __('settings.when_you_enable_two_factor_authentication_you_will_be_prompt') }}
                        </x-slot:description>

                        @if (! $twoFactorEnabled || ! $twoFactorRequired)
                            <x-slot:actions>
                                @if ($twoFactorEnabled)
                                    <x-ui.button size="sm" wire:click="disable" class="border">
                                        {{ __('settings.disable_2fa') }}
                                    </x-ui.button>
                                @else
                                    <flux:modal.trigger name="two-factor-setup-modal">
                                        <x-ui.button
                                            :tone="$twoFactorRequired ? 'primary' : 'secondary'"
                                            size="sm"
                                            wire:click="$dispatch('start-two-factor-setup')"
                                            class="border"
                                        >
                                            {{ __('settings.enable_2fa') }}
                                        </x-ui.button>
                                    </flux:modal.trigger>
                                @endif
                            </x-slot:actions>
                        @endif

                        @if ($twoFactorEnabled)
                            @if ($twoFactorRequired)
                                <p class="text-secondary-medium dark:text-ink-muted" data-test="two-factor-required-cannot-disable">
                                    {{ __('settings.two_factor_required_cannot_disable') }}
                                </p>
                            @endif

                            <livewire:pages::settings.two-factor.recovery-codes :$requiresConfirmation />
                        @endif
                    </x-security.step>
                @endif
            </ol>

            @if ($appStepAvailable && ! $twoFactorEnabled)
                <livewire:pages::settings.two-factor-setup-modal :requires-confirmation="$requiresConfirmation" />
            @endif
        </section>

        @if ($canManagePasskeys)
            <section aria-labelledby="passkeys-heading" class="grid gap-5 border-t border-secondary-soft pt-10 dark:border-line" data-test="passkeys">
                <div>
                    <h2 id="passkeys-heading" class="text-xl font-bold">{{ __('settings.passkeys') }}</h2>
                    <p class="mt-1 text-sm text-secondary-medium dark:text-ink-muted">{{ __('settings.manage_your_passkeys_for_passwordless_sign_in') }}</p>
                </div>

                @if ($passkeys === [])
                    <div class="flex items-start gap-3 text-sm" wire:cloak>
                        <flux:icon.finger-print variant="outline" class="size-5 shrink-0 text-secondary-light dark:text-ink-muted" />
                        <div>
                            <p class="font-semibold">{{ __('settings.no_passkeys_yet') }}</p>
                            <p class="text-secondary-medium dark:text-ink-muted">{{ __('settings.add_a_passkey_to_sign_in_without_a_password') }}</p>
                        </div>
                    </div>
                @else
                    <ul class="divide-y divide-secondary-soft border-y border-secondary-soft text-sm dark:divide-line dark:border-line" wire:cloak>
                        @foreach ($passkeys as $passkey)
                            <li class="flex items-center gap-4 py-3" wire:key="passkey-{{ $passkey['id'] }}">
                                <flux:icon.key variant="outline" class="size-5 shrink-0 text-secondary-light dark:text-ink-muted" />
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="truncate font-semibold">{{ $passkey['name'] }}</p>
                                        @if ($passkey['authenticator'])
                                            <span class="type-meta rounded-full bg-secondary-soft/60 px-2 py-0.5 text-secondary-medium dark:bg-white/10 dark:text-ink-muted">{{ $passkey['authenticator'] }}</span>
                                        @endif
                                    </div>
                                    <p class="type-meta mt-0.5 text-secondary-medium dark:text-ink-muted">
                                        {{ __('settings.added_time', ['time' => $passkey['created_at_diff']]) }}
                                        @if ($passkey['last_used_at_diff'])
                                            <span aria-hidden="true" class="mx-1">·</span>
                                            {{ __('settings.last_used_time', ['time' => $passkey['last_used_at_diff']]) }}
                                        @endif
                                    </p>
                                </div>

                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    icon="trash"
                                    icon:variant="outline"
                                    wire:click="confirmDelete({{ $passkey['id'] }})"
                                    :aria-label="__('settings.remove_passkey')"
                                    class="text-primary hover:bg-primary/10 dark:text-ink dark:hover:bg-white/10"
                                />
                            </li>
                        @endforeach
                    </ul>
                @endif

                <x-passkey-registration />
            </section>
        @endif

        {{-- Devices: where this account is signed in, and which browsers skip the sign-in code. --}}
        <section aria-labelledby="devices-heading" class="grid gap-10 border-t border-secondary-soft pt-10 dark:border-line">
            <h2 id="devices-heading" class="text-xl font-bold">{{ __('settings.your_devices') }}</h2>

            @if ($canManageSignedInDevices)
                @php
                    $signedInDevices = $this->signedInDevices;
                @endphp

                <div class="grid gap-4" data-test="signed-in-devices">
                    <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
                        <div class="min-w-0 flex-1 basis-64">
                            <h3 class="type-heading">{{ __('settings.signed_in_devices') }}</h3>
                            <p class="mt-1 text-sm text-secondary-medium dark:text-ink-muted">{{ __('settings.signed_in_devices_description') }}</p>
                        </div>

                        @if (collect($signedInDevices)->contains('current', false))
                            <x-ui.button size="sm" icon="arrow-right-start-on-rectangle" wire:click="signOutOtherDevices" class="border">
                                {{ __('settings.sign_out_other_devices') }}
                            </x-ui.button>
                        @endif
                    </div>

                    <ul class="divide-y divide-secondary-soft border-y border-secondary-soft text-sm dark:divide-line dark:border-line" wire:cloak>
                        @foreach ($signedInDevices as $device)
                            <li class="flex items-center gap-4 py-3" wire:key="signed-in-device-{{ $loop->index }}">
                                <flux:icon
                                    :name="$device['mobile'] ? 'device-phone-mobile' : 'computer-desktop'"
                                    variant="outline"
                                    class="size-5 shrink-0 text-secondary-light dark:text-ink-muted"
                                />
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="font-semibold">{{ $device['label'] }}</p>
                                        @if ($device['current'])
                                            <span class="type-meta rounded-full bg-status-good-soft px-2 py-0.5 text-status-good">{{ __('settings.this_device') }}</span>
                                        @endif
                                    </div>
                                    <p class="type-meta mt-0.5 text-secondary-medium dark:text-ink-muted">
                                        @if ($device['ip_address'])
                                            <span dir="ltr">{{ $device['ip_address'] }}</span>
                                            <span aria-hidden="true" class="mx-1">·</span>
                                        @endif
                                        {{ $device['current'] ? __('settings.active_now') : __('settings.last_active_time', ['time' => $device['last_active']]) }}
                                    </p>
                                </div>
                            </li>
                        @endforeach
                    </ul>

                    @unless (collect($signedInDevices)->contains('current', false))
                        <p class="text-sm text-secondary-medium dark:text-ink-muted">{{ __('settings.no_other_devices_are_signed_in') }}</p>
                    @endunless
                </div>
            @endif

            @php
                $trustedDevices = $this->trustedDevices;
            @endphp

            <div class="grid gap-4" data-test="trusted-devices">
                <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
                    <div class="min-w-0 flex-1 basis-64">
                        <h3 class="type-heading">{{ __('settings.trusted_devices') }}</h3>
                        <p class="mt-1 text-sm text-secondary-medium dark:text-ink-muted">{{ __('settings.trusted_devices_description') }}</p>
                    </div>

                    @if ($trustedDevices !== [])
                        <x-ui.button size="sm" icon="shield-exclamation" wire:click="forgetTrustedDevices" data-test="forget-trusted-devices" class="border">
                            {{ __('settings.forget_trusted_devices') }}
                        </x-ui.button>
                    @endif
                </div>

                @if ($trustedDevices === [])
                    <p class="text-sm text-secondary-medium dark:text-ink-muted">{{ __('settings.no_trusted_devices') }}</p>
                @else
                    <ul class="divide-y divide-secondary-soft border-y border-secondary-soft text-sm dark:divide-line dark:border-line" wire:cloak>
                        @foreach ($trustedDevices as $device)
                            <li class="flex items-center gap-4 py-3" wire:key="trusted-device-{{ $loop->index }}">
                                <flux:icon name="shield-check" variant="outline" class="size-5 shrink-0 text-secondary-light dark:text-ink-muted" />
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="font-semibold">{{ $device['label'] }}</p>
                                        @if ($device['current'])
                                            <span class="type-meta rounded-full bg-status-good-soft px-2 py-0.5 text-status-good">{{ __('settings.this_device') }}</span>
                                        @endif
                                    </div>
                                    <p class="type-meta mt-0.5 text-secondary-medium dark:text-ink-muted">
                                        {{ __('settings.last_active_time', ['time' => $device['last_used']]) }}
                                        <span aria-hidden="true" class="mx-1">·</span>
                                        {{ __('settings.trusted_until', ['date' => $device['expires']]) }}
                                    </p>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </section>
    </div>

    <flux:modal
        name="delete-passkey-modal"
        class="max-w-md md:min-w-md"
        @close="closeDeleteModal"
        wire:model="showDeleteModal"
    >
        <div class="space-y-6">
            <div class="space-y-2">
                <flux:heading size="lg">{{ __('settings.remove_passkey') }}</flux:heading>
                <flux:text>
                    {{ __('settings.are_you_sure_you_want_to_remove_the_passkey_name_you_will_no', ['name' => $deletingPasskeyName]) }}
                </flux:text>
            </div>

            <div class="flex justify-end gap-3">
                <x-ui.button wire:click="closeDeleteModal" class="border">
                    {{ __('common.cancel') }}
                </x-ui.button>
                <flux:button variant="danger" wire:click="deletePasskey">
                    {{ __('settings.remove_passkey') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</section>
