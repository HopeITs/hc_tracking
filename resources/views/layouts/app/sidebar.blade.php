@php
    /*
     * Someone who still has to turn on two-factor authentication only reaches
     * the security page: the inbox bell, its live channel and the badge
     * counts stay hidden until they have (see EnsureTwoFactorIsEnabled).
     */
    $showsInbox = auth()->check() && ! \App\Http\Middleware\EnsureTwoFactorIsEnabled::mustEnrol(auth()->user());
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Enums\Locale::current()->direction() }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-surface-0" @if ($showsInbox) data-user-id="{{ auth()->id() }}" data-notification-popups="{{ auth()->user()->notification_popups ? 'on' : 'off' }}" data-session-lifetime="{{ (int) config('session.lifetime') }}" @endif>
        @persist('preloader')
            <x-preloader />
        @endpersist

        <x-navigation.sidebar />

        <!-- Mobile User Menu -->
        <flux:header sticky class="z-40 min-h-16 border-b border-secondary-soft bg-white px-3 py-2 lg:hidden dark:border-line dark:bg-surface-1" data-top-bar-actions>
            <flux:sidebar.toggle
                class="size-11! touch-manipulation lg:hidden"
                icon="bars-2"
                inset="left"
                data-mobile-navigation-toggle
            />

            <flux:spacer />

            <x-notifications.install-button />

            @if ($showsInbox)
                <livewire:notifications.bell />
            @endif

            <x-report-bug-button />

            <x-guideline-button />

            <x-tour.button />

            <flux:dropdown position="top" align="end" data-tour="profile">
                <flux:profile
                    icon-trailing="chevron-down"
                    class="min-h-11 min-w-11 touch-manipulation"
                >
                    <x-slot:avatar>
                        <flux:icon.user class="size-5" />
                    </x-slot:avatar>
                </flux:profile>

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="px-1 py-1.5 text-start text-sm">
                                <div class="grid text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.item :href="route('security.edit')" icon="shield-check" class="min-h-11 touch-manipulation" data-test="security-menu-item">
                        {{ __('common.security') }}
                    </flux:menu.item>

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="min-h-11 w-full cursor-pointer touch-manipulation rtl:[&_svg]:-scale-x-100"
                            data-test="logout-button"
                        >
                            {{ __('common.log_out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        <!-- Desktop Top Navigation -->
        <flux:header sticky class="z-40 hidden min-h-16 border-b border-secondary-soft bg-white px-4 py-2 lg:flex dark:border-line dark:bg-surface-1" data-desktop-navigation-header data-top-bar-actions>
            <flux:spacer />

            <div class="flex items-center gap-1.5">
                <x-notifications.install-button />

                @if ($showsInbox)
                    <livewire:notifications.bell />
                @endif

                <x-report-bug-button />

                <x-tour.button />

                {{-- Light / Dark / System. The choice lives in Flux's $flux.appearance; resources/js/theme-transition.js cross-fades the switch. --}}
                <flux:dropdown x-data position="bottom" align="end">
                    <flux:button
                        variant="ghost"
                        size="sm"
                        square
                        class="size-9! touch-manipulation text-secondary-medium! hover:bg-secondary-soft! hover:text-secondary-dark! dark:text-ink-muted! dark:hover:bg-white/10! dark:hover:text-ink!"
                        :aria-label="__('common.appearance')"
                        :tooltip="__('common.appearance')"
                        tooltip:position="bottom"
                        data-theme-toggle
                        data-top-bar-action
                    >
                        <flux:icon.sun x-show="$flux.appearance === 'light'" x-cloak variant="mini" />
                        <flux:icon.moon x-show="$flux.appearance === 'dark'" x-cloak variant="mini" />
                        <flux:icon.computer-desktop x-show="$flux.appearance === 'system'" x-cloak variant="mini" />
                    </flux:button>

                    <flux:menu :aria-label="__('common.appearance')">
                        <flux:menu.radio.group x-bind:value="$flux.appearance">
                            <flux:menu.radio value="light" icon:trailing="sun" class="touch-manipulation" x-on:click="hcSetAppearance('light')" data-appearance-option="light">
                                {{ __('navigation.appearance_light') }}
                            </flux:menu.radio>
                            <flux:menu.radio value="dark" icon:trailing="moon" class="touch-manipulation" x-on:click="hcSetAppearance('dark')" data-appearance-option="dark">
                                {{ __('navigation.appearance_dark') }}
                            </flux:menu.radio>
                            <flux:menu.radio value="system" icon:trailing="computer-desktop" class="touch-manipulation" x-on:click="hcSetAppearance('system')" data-appearance-option="system">
                                {{ __('navigation.appearance_system') }}
                            </flux:menu.radio>
                        </flux:menu.radio.group>
                    </flux:menu>
                </flux:dropdown>

                <x-guideline-button />

                <x-locale-toggle />

                <div class="ms-1 border-s border-secondary-soft ps-2 dark:border-line" data-tour="profile">
                    <x-desktop-user-menu />
                </div>
            </div>
        </flux:header>

        {{ $slot }}

        <x-tour.overlay />

        @persist('toast')
            <flux:toast.group position="center">
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @auth
            @persist('notification-toasts')
                <x-notifications.toasts />
            @endpersist

            <x-notifications.install-modal />
        @endauth

        @fluxScripts
    </body>
</html>
