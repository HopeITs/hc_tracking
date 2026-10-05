<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Enums\Locale::current()->direction() }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-auth-ground antialiased dark:bg-surface-0">
        @persist('preloader')
            <x-preloader />
        @endpersist

        <div class="flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div class="flex w-full max-w-sm flex-col items-center gap-6">
                <a href="{{ route('home') }}" class="flex flex-col items-center" wire:navigate>
                    <img src="{{ asset('logo-tracking.svg') }}" alt="" class="h-16 w-auto dark:hidden" data-no-dim />
                    <img src="{{ asset('logo-tracking-white.svg') }}" alt="" class="hidden h-16 w-auto dark:block" data-no-dim />
                    <span class="sr-only">{{ config('app.name', 'Laravel') }}</span>
                </a>

                <div class="w-full rounded-2xl border border-secondary-soft bg-white p-8 shadow-card dark:border-line dark:bg-surface-1">
                    {{ $slot }}
                </div>

                <p class="text-center text-xs text-secondary-medium dark:text-ink-muted">
                    {{ __('authentication.copyright_notice', ['year' => now()->year]) }}
                </p>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group position="center">
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
