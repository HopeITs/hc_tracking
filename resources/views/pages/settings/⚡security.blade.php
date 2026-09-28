<?php

use App\Actions\Fortify\TrustedDevices;
use App\Http\Middleware\EnsureTwoFactorIsEnabled;
use App\Http\UserAgent;
use App\Livewire\Concerns\RequiresConfirmedPassword;
use App\Models\TrustedDevice;
use App\Models\User;
use Flux\Flux;
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
/* @chisel-passkeys */
use Laravel\Passkeys\Actions\DeletePasskey;
/* @end-chisel-passkeys */
/* @chisel-2fa */
use Livewire\Attributes\On;
/* @end-chisel-2fa */

new #[Title('titles.security_settings')] class extends Component {
    use RequiresConfirmedPassword;

    /* @chisel-2fa */
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
    /* @end-chisel-2fa */

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

    /* @chisel-passkeys */
    #[Locked]
    public bool $canManagePasskeys;

    #[Locked]
    public array $passkeys = [];

    public bool $showDeleteModal = false;

    #[Locked]
    public ?int $deletingPasskeyId = null;

    #[Locked]
    public string $deletingPasskeyName = '';
    /* @end-chisel-passkeys */

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
        /* @chisel-2fa */
        $this->canManageTwoFactor = Features::canManageTwoFactorAuthentication();

        if ($this->canManageTwoFactor) {
            if (Fortify::confirmsTwoFactorAuthentication() && is_null(auth()->user()->two_factor_confirmed_at)) {
                $disableTwoFactorAuthentication(auth()->user());
            }

            $this->twoFactorEnabled = auth()->user()->hasEnabledTwoFactorAuthentication();
            $this->requiresConfirmation = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
            $this->twoFactorRequired = EnsureTwoFactorIsEnabled::appIsOnlyFactorFor(auth()->user());
        }
        /* @end-chisel-2fa */

        $this->emailTwoFactorEnabled = auth()->user()->usesEmailTwoFactor();
        $this->emailTwoFactorRequired = EnsureTwoFactorIsEnabled::emailCodeRequiredFor(auth()->user());

        $this->canManageSignedInDevices = config('session.driver') === 'database';

        /* @chisel-passkeys */
        $this->canManagePasskeys = Features::canManagePasskeys();

        if ($this->canManagePasskeys) {
            $this->loadPasskeys();
        }
        /* @end-chisel-passkeys */
    }


    /* @chisel-passkeys */
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
    /* @end-chisel-passkeys */

    /* @chisel-2fa */
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
    }
    /* @end-chisel-2fa */

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
     * devices straight back in.
     */
    public function signOutOtherDevices(): void
    {
        if (! $this->canManageSignedInDevices || ! $this->ensurePasswordIsConfirmed()) {
            return;
        }

        $this->sessionRows()->where('id', '!=', session()->getId())->delete();

        $this->rotateRememberToken(auth()->user());

        TrustedDevices::forgetOthers(request(), auth()->user());

        unset($this->signedInDevices, $this->trustedDevices);

        Flux::toast(variant: 'success', text: __('settings.other_devices_signed_out'));
    }

    /**
     * This account's rows in the session table.
     */
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

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('settings.security_settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('common.password')" :subheading="__('settings.you_sign_in_with_your_hr_system_account')">
        <flux:text class="mt-6" data-test="hr-password-notice">
            {{ __('settings.your_password_is_managed_in_the_hr_system_change_it_there_th') }}
        </flux:text>

        <section class="mt-12" data-test="email-two-factor">
            <flux:heading>{{ __('settings.email_2fa_heading') }}</flux:heading>
            <flux:subheading>{{ __('settings.email_2fa_description') }}</flux:subheading>

            <div class="mt-4 space-y-4 text-sm">
                <flux:text>{{ __($emailTwoFactorEnabled ? 'settings.email_2fa_is_on' : 'settings.email_2fa_is_off') }}</flux:text>

                @if ($emailTwoFactorEnabled && ($canManageTwoFactor ?? false) && $twoFactorEnabled)
                    <flux:text variant="subtle">{{ __('settings.email_2fa_app_takes_priority') }}</flux:text>
                @endif

                @if ($emailTwoFactorRequired)
                    <flux:text variant="subtle" data-test="email-2fa-required">{{ __('settings.email_2fa_required') }}</flux:text>
                @elseif ($emailTwoFactorEnabled)
                    <flux:button variant="outline" wire:click="setEmailTwoFactor(false)" data-test="email-2fa-disable">
                        {{ __('settings.email_2fa_disable') }}
                    </flux:button>
                @else
                    <flux:button variant="primary" wire:click="setEmailTwoFactor(true)" data-test="email-2fa-enable">
                        {{ __('settings.email_2fa_enable') }}
                    </flux:button>
                @endif
            </div>
        </section>

        {{-- @chisel-2fa --}}
        @if ($canManageTwoFactor)
            <section class="mt-12">
                <flux:heading>{{ __('settings.app_2fa_heading') }}</flux:heading>
                <flux:subheading>{{ __('settings.manage_your_two_factor_authentication_settings') }}</flux:subheading>

                @if ($twoFactorRequired && ! $twoFactorEnabled)
                    <flux:callout variant="warning" icon="shield-exclamation" class="mt-6" data-test="two-factor-required-notice">
                        <flux:callout.heading>{{ __('settings.two_factor_required_heading') }}</flux:callout.heading>
                        <flux:callout.text>{{ __('settings.two_factor_required_explanation') }}</flux:callout.text>
                    </flux:callout>
                @endif

                <div class="flex flex-col w-full mx-auto space-y-6 text-sm" wire:cloak>
                    @if ($twoFactorEnabled)
                        <div class="space-y-4">
                            <flux:text>
                                {{ __('settings.you_will_be_prompted_for_a_secure_random_pin_during_login_wh') }}
                            </flux:text>

                            @if ($twoFactorRequired)
                                <flux:text variant="subtle" data-test="two-factor-required-cannot-disable">
                                    {{ __('settings.two_factor_required_cannot_disable') }}
                                </flux:text>
                            @else
                                <div class="flex justify-start">
                                    <flux:button
                                        variant="danger"
                                        wire:click="disable"
                                    >
                                        {{ __('settings.disable_2fa') }}
                                    </flux:button>
                                </div>
                            @endif

                            <livewire:pages::settings.two-factor.recovery-codes :$requiresConfirmation />
                        </div>
                    @else
                        <div class="space-y-4">
                            <flux:text variant="subtle">
                                {{ __('settings.when_you_enable_two_factor_authentication_you_will_be_prompt') }}
                            </flux:text>

                            <flux:modal.trigger name="two-factor-setup-modal">
                                <flux:button
                                    variant="primary"
                                    wire:click="$dispatch('start-two-factor-setup')"
                                >
                                    {{ __('settings.enable_2fa') }}
                                </flux:button>
                            </flux:modal.trigger>

                            <livewire:pages::settings.two-factor-setup-modal :requires-confirmation="$requiresConfirmation" />
                        </div>
                    @endif
                </div>
            </section>
        @endif
        {{-- @end-chisel-2fa --}}

        {{-- @chisel-passkeys --}}
        @if ($canManagePasskeys)
            <section class="mt-12">
                <flux:heading>{{ __('settings.passkeys') }}</flux:heading>
                <flux:subheading>{{ __('settings.manage_your_passkeys_for_passwordless_sign_in') }}</flux:subheading>

                <div class="mt-6 flex flex-col w-full mx-auto space-y-6 text-sm" wire:cloak>
                    <div class="border rounded-lg border-zinc-200 dark:border-zinc-700 overflow-hidden">
                        @forelse ($passkeys as $passkey)
                            <div class="flex items-center justify-between p-4 {{ ! $loop->last ? 'border-b border-zinc-200 dark:border-zinc-700' : '' }}">
                                <div class="flex items-center gap-4">
                                    <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-zinc-100 dark:bg-zinc-800">
                                        <flux:icon.key class="size-5 text-zinc-500 dark:text-zinc-400" />
                                    </div>
                                    <div class="space-y-1">
                                        <div class="flex items-center gap-2.5">
                                            <p class="font-medium tracking-tight">{{ $passkey['name'] }}</p>
                                            @if ($passkey['authenticator'])
                                                <flux:badge size="sm">{{ $passkey['authenticator'] }}</flux:badge>
                                            @endif
                                        </div>
                                        <p class="text-zinc-500 dark:text-zinc-400 text-xs">
                                            {{ __('settings.added_time', ['time' => $passkey['created_at_diff']]) }}
                                            @if ($passkey['last_used_at_diff'])
                                                <span class="opacity-50 mx-1">/</span>
                                                {{ __('settings.last_used_time', ['time' => $passkey['last_used_at_diff']]) }}
                                            @endif
                                        </p>
                                    </div>
                                </div>

                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    icon="trash"
                                    icon:variant="outline"
                                    wire:click="confirmDelete({{ $passkey['id'] }})"
                                    class="text-red-500 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-950/50"
                                />
                            </div>
                        @empty
                            <div class="p-8 text-center">
                                <div class="mx-auto mb-4 flex size-14 items-center justify-center rounded-2xl bg-zinc-100 dark:bg-zinc-800">
                                    <flux:icon.key class="size-7 text-zinc-400 dark:text-zinc-500" />
                                </div>
                                <p class="font-medium">{{ __('settings.no_passkeys_yet') }}</p>
                                <flux:text class="mt-1">{{ __('settings.add_a_passkey_to_sign_in_without_a_password') }}</flux:text>
                            </div>
                        @endforelse
                    </div>

                    <x-passkey-registration />
                </div>
            </section>
        @endif
        {{-- @end-chisel-passkeys --}}

        @if ($canManageSignedInDevices)
            @php
                $signedInDevices = $this->signedInDevices;
            @endphp

            <section class="mt-12" data-test="signed-in-devices">
                <flux:heading>{{ __('settings.signed_in_devices') }}</flux:heading>
                <flux:subheading>{{ __('settings.signed_in_devices_description') }}</flux:subheading>

                <ul class="mt-6 divide-y divide-zinc-200 text-sm dark:divide-zinc-700" wire:cloak>
                    @foreach ($signedInDevices as $device)
                        <li class="flex items-center gap-4 py-3" wire:key="signed-in-device-{{ $loop->index }}">
                            <flux:icon
                                :name="$device['mobile'] ? 'device-phone-mobile' : 'computer-desktop'"
                                variant="outline"
                                class="size-5 shrink-0 text-zinc-400 dark:text-zinc-500"
                            />
                            <div class="min-w-0 space-y-1">
                                <div class="flex items-center gap-2.5">
                                    <p class="font-medium tracking-tight">{{ $device['label'] }}</p>
                                    @if ($device['current'])
                                        <flux:badge size="sm" color="green">{{ __('settings.this_device') }}</flux:badge>
                                    @endif
                                </div>
                                <p class="text-zinc-500 dark:text-zinc-400 text-xs">
                                    @if ($device['ip_address'])
                                        <span dir="ltr">{{ $device['ip_address'] }}</span>
                                        <span class="opacity-50 mx-1">/</span>
                                    @endif
                                    {{ $device['current'] ? __('settings.active_now') : __('settings.last_active_time', ['time' => $device['last_active']]) }}
                                </p>
                            </div>
                        </li>
                    @endforeach
                </ul>

                @if (collect($signedInDevices)->contains('current', false))
                    <flux:button variant="outline" size="sm" class="mt-4" wire:click="signOutOtherDevices">
                        {{ __('settings.sign_out_other_devices') }}
                    </flux:button>
                @else
                    <flux:text class="mt-4">{{ __('settings.no_other_devices_are_signed_in') }}</flux:text>
                @endif
            </section>
        @endif

        @php
            $trustedDevices = $this->trustedDevices;
        @endphp

        <section class="mt-12" data-test="trusted-devices">
            <flux:heading>{{ __('settings.trusted_devices') }}</flux:heading>
            <flux:subheading>{{ __('settings.trusted_devices_description') }}</flux:subheading>

            @if ($trustedDevices === [])
                <flux:text class="mt-4">{{ __('settings.no_trusted_devices') }}</flux:text>
            @else
                <ul class="mt-6 divide-y divide-zinc-200 text-sm dark:divide-zinc-700" wire:cloak>
                    @foreach ($trustedDevices as $device)
                        <li class="flex items-center gap-4 py-3" wire:key="trusted-device-{{ $loop->index }}">
                            <flux:icon name="shield-check" variant="outline" class="size-5 shrink-0 text-zinc-400 dark:text-zinc-500" />
                            <div class="min-w-0 space-y-1">
                                <div class="flex items-center gap-2.5">
                                    <p class="font-medium tracking-tight">{{ $device['label'] }}</p>
                                    @if ($device['current'])
                                        <flux:badge size="sm" color="green">{{ __('settings.this_device') }}</flux:badge>
                                    @endif
                                </div>
                                <p class="text-zinc-500 dark:text-zinc-400 text-xs">
                                    {{ __('settings.last_active_time', ['time' => $device['last_used']]) }}
                                    <span class="opacity-50 mx-1">/</span>
                                    {{ __('settings.trusted_until', ['date' => $device['expires']]) }}
                                </p>
                            </div>
                        </li>
                    @endforeach
                </ul>

                <flux:button variant="outline" size="sm" class="mt-4" wire:click="forgetTrustedDevices" data-test="forget-trusted-devices">
                    {{ __('settings.forget_trusted_devices') }}
                </flux:button>
            @endif
        </section>
    </x-pages::settings.layout>

    {{-- @chisel-passkeys --}}
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

            <div class="flex gap-3 justify-end">
                <flux:button
                    variant="outline"
                    wire:click="closeDeleteModal"
                >
                    {{ __('common.cancel') }}
                </flux:button>
                <flux:button
                    variant="danger"
                    wire:click="deletePasskey"
                >
                    {{ __('settings.remove_passkey') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
    {{-- @end-chisel-passkeys --}}
</section>
