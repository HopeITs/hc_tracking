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

            {{-- The icon actions share one track, the same one the language toggle sits on. --}}
            <div class="me-1 inline-flex items-center gap-1 rounded-full bg-secondary-soft/40 p-1 dark:bg-surface-2" data-top-bar-action-group>
                <x-notifications.install-button />

                @if ($showsInbox)
                    <livewire:notifications.bell />
                @endif

                <x-report-bug-button />

                <x-guideline-button />

                <x-tour.button />
            </div>

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

            <div class="flex items-center gap-3">
                <x-locale-toggle />

                {{-- The icon actions share one track, the same one the language toggle sits on. --}}
                <div class="inline-flex items-center gap-2 rounded-full bg-secondary-soft/40 px-2 py-1 dark:bg-surface-2" data-top-bar-action-group>
                    <x-notifications.install-button />

                    @if ($showsInbox)
                        <livewire:notifications.bell />
                    @endif

                    <x-report-bug-button />

                    <x-guideline-button />

                    <x-tour.button />

                    {{-- One press flips Light / Dark; the icon shows the theme in use. resources/js/theme-transition.js stores the choice in Flux's $flux.appearance and cross-fades the switch. --}}
                    <flux:button
                        x-data
                        x-on:click="hcToggleAppearance()"
                        variant="ghost"
                        size="sm"
                        square
                        class="size-8! touch-manipulation text-secondary-medium! hover:bg-white! hover:text-secondary-dark! hover:shadow-sm dark:text-ink-muted! dark:hover:bg-surface-3! dark:hover:text-ink! dark:hover:shadow-raised"
                        :aria-label="__('common.appearance')"
                        :tooltip="__('common.appearance')"
                        tooltip:position="bottom"
                        data-theme-toggle
                        data-top-bar-action
                    >
                        <flux:icon.sun x-show="! $flux.dark" x-cloak variant="outline" class="size-4.5!" />
                        <flux:icon.moon x-show="$flux.dark" x-cloak variant="outline" class="size-4.5!" />
                    </flux:button>
                </div>

                <div class="border-s border-secondary-soft ps-2 dark:border-line" data-tour="profile">
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
