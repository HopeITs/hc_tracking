@props([
    'code',
    'tone' => 'neutral',
    'heading',
    'body',
])

@php
    $toneText = match ($tone) {
        'critical' => 'text-status-critical',
        'warn' => 'text-status-warn',
        'active' => 'text-status-active',
        default => 'text-secondary-medium dark:text-ink-faint',
    };
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Enums\Locale::current()->direction() }}">
    <head>
        @include('partials.head', ['title' => $heading])
    </head>
    <body class="min-h-screen bg-auth-ground antialiased dark:bg-surface-0">
        <div class="error-page relative flex min-h-svh flex-col items-center justify-center overflow-hidden p-6 md:p-10">

            {{-- The tracking route: a faint path threading from origin to destination, with its waypoints along the way. Purely ambient, never a UI element. --}}
            <svg class="error-route pointer-events-none absolute inset-0 size-full text-secondary-dark/[0.09] dark:text-white/[0.1]" viewBox="0 0 1200 800" preserveAspectRatio="xMidYMid slice" fill="none" aria-hidden="true">
                <path d="M-60 60 C 140 60, 160 220, 340 240 S 560 140, 700 260 S 760 520, 940 560 S 1020 700, 1220 660" stroke="currentColor" stroke-width="3" stroke-dasharray="2 22" stroke-linecap="round" />
                <circle cx="-60" cy="60" r="8" fill="currentColor" />
                <circle cx="340" cy="240" r="8" fill="currentColor" />
                <circle cx="700" cy="260" r="8" fill="currentColor" />
                <circle cx="940" cy="560" r="8" fill="currentColor" />
                <path transform="translate(1195, 630)" d="M0 0 C 0 -28, 28 -28, 28 0 C 28 22, 14 37, 14 50 C 14 37, 0 22, 0 0 Z" fill="currentColor" />
            </svg>

            <div class="relative flex w-full max-w-lg flex-col items-center gap-8 text-center">
                <a href="{{ route('home') }}" class="flex flex-col items-center">
                    <img src="{{ asset('logo-tracking.svg') }}" alt="" class="h-11 w-auto dark:hidden" data-no-dim />
                    <img src="{{ asset('logo-tracking-white.svg') }}" alt="" class="hidden h-11 w-auto dark:block" data-no-dim />
                    <span class="sr-only">{{ config('app.name', 'Laravel') }}</span>
                </a>

                <div>
                    <p @class(['type-label', $toneText]) dir="ltr">{{ __('errors.error_code', ['code' => $code]) }}</p>
                    <h1 class="mt-3 text-4xl font-extrabold tracking-tight text-balance text-secondary-dark sm:text-5xl dark:text-ink">{{ $heading }}</h1>
                    <p class="mx-auto mt-4 max-w-sm text-base text-pretty text-secondary-medium dark:text-ink-muted">{{ $body }}</p>
                </div>

                <x-ui.button tone="primary" :href="route('dashboard')" class="!px-6 !py-3 !text-base">
                    {{ __('errors.back_to_dashboard') }}
                </x-ui.button>

                <p class="text-center text-xs text-secondary-medium dark:text-ink-muted">
                    {{ __('authentication.copyright_notice', ['year' => now()->year]) }}
                </p>
            </div>
        </div>

        @fluxScripts
    </body>
</html>
