<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Enums\Locale::current()->direction() }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-[#e5e7eb] antialiased dark:bg-secondary-dark">
        @persist('preloader')
            <x-preloader />
        @endpersist

        <div class="flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div class="flex w-full max-w-sm flex-col items-center gap-6">
                <a href="{{ route('home') }}" class="flex flex-col items-center" wire:navigate>
                    <img src="{{ asset('logo-tracking.svg') }}" alt="" class="h-16 w-auto dark:hidden" />
                    <img src="{{ asset('logo-tracking-white.svg') }}" alt="" class="hidden h-16 w-auto dark:block" />
                    <span class="sr-only">{{ config('app.name', 'Laravel') }}</span>
                </a>

                <div class="w-full rounded-2xl border border-secondary-soft bg-white p-8 shadow-[0_1px_2px_rgba(16,24,40,0.04),0_1px_3px_rgba(16,24,40,0.06)] dark:border-secondary-medium dark:bg-secondary-dark">
                    {{ $slot }}
                </div>

                <p class="text-center text-xs text-secondary-medium dark:text-secondary-soft">
                    {{ __('authentication.copyright_notice', ['year' => now()->year]) }}
                </p>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
