<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Enums\Locale::current()->direction() }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <x-navigation.sidebar />

        <!-- Mobile User Menu -->
        <flux:header class="min-h-16 px-3 py-2 lg:hidden">
            <flux:sidebar.toggle
                class="size-11! touch-manipulation lg:hidden"
                icon="bars-2"
                inset="left"
                data-mobile-navigation-toggle
            />

            <flux:spacer />

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

                    <flux:menu.radio.group>
                        <flux:menu.item class="min-h-11 touch-manipulation" :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('common.settings') }}
                        </flux:menu.item>
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

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
