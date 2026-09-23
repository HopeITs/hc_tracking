<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Enums\Locale::current()->direction() }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800" @auth data-user-id="{{ auth()->id() }}" data-notification-popups="{{ auth()->user()->notification_popups ? 'on' : 'off' }}" @endauth>
        <x-navigation.sidebar />

        <!-- Mobile User Menu -->
        <flux:header sticky class="z-40 min-h-16 border-b border-secondary-soft bg-white px-3 py-2 lg:hidden dark:border-secondary-medium dark:bg-secondary-dark">
            <flux:sidebar.toggle
                class="size-11! touch-manipulation lg:hidden"
                icon="bars-2"
                inset="left"
                data-mobile-navigation-toggle
            />

            <flux:spacer />

            <livewire:notifications.bell />

            <flux:dropdown position="top" align="end">
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
        <flux:header sticky class="z-40 hidden min-h-16 border-b border-secondary-soft bg-white px-4 py-2 lg:flex dark:border-secondary-medium dark:bg-secondary-dark" data-desktop-navigation-header>
            <flux:spacer />

            <div class="flex items-center gap-1.5">
                <livewire:notifications.bell />

                <flux:button
                    x-data
                    x-on:click="$flux.dark = ! $flux.dark"
                    variant="ghost"
                    size="sm"
                    square
                    class="size-9! touch-manipulation text-secondary-medium! hover:bg-secondary-soft! hover:text-secondary-dark! dark:text-secondary-soft! dark:hover:bg-white/10! dark:hover:text-white!"
                    :aria-label="__('navigation.toggle_theme')"
                    :tooltip="__('navigation.toggle_theme')"
                    data-theme-toggle
                >
                    <flux:icon.moon x-show="! $flux.dark" variant="mini" />
                    <flux:icon.sun x-show="$flux.dark" x-cloak variant="mini" />
                </flux:button>

                <x-locale-toggle />

                <div class="ms-1 border-s border-secondary-soft ps-2 dark:border-secondary-medium">
                    <x-desktop-user-menu />
                </div>
            </div>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @auth
            @persist('notification-toasts')
                <x-notifications.toasts />
            @endpersist
        @endauth

        @fluxScripts
    </body>
</html>
