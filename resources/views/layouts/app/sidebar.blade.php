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
    <body class="dk-body min-h-screen" @if ($showsInbox) data-user-id="{{ auth()->id() }}" data-notification-popups="{{ auth()->user()->notification_popups ? 'on' : 'off' }}" data-session-lifetime="{{ (int) config('session.lifetime') }}" @endif>
        @persist('preloader')
            <x-preloader />
        @endpersist

        <div class="dk-app" data-app-shell>
            @auth
                <x-desk.side-nav :shows-inbox="$showsInbox" />
            @endauth

            <div class="dk-main" data-app-main>
                <x-desk.top-bar :shows-inbox="$showsInbox" />

                {{ $slot }}
            </div>
        </div>

        @if ($showsInbox)
            <livewire:desk.ask />
            @if (request()->routeIs('dashboard'))
                <livewire:desk.page-panels :route-name="request()->route()?->getName() ?? ''" :page-title="filled($title ?? null) ? __($title) : null" />
            @endif
        @endif

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
