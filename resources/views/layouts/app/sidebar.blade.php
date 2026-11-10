@php
    /*
     * Someone who still has to turn on two-factor authentication only reaches
     * the security page: the inbox bell, its live channel and the badge
     * counts stay hidden until they have (see EnsureTwoFactorIsEnabled).
     */
    $showsInbox = auth()->check() && ! \App\Http\Middleware\EnsureTwoFactorIsEnabled::mustEnrol(auth()->user());
    /*
     * A page opened in a Desk tab (App\Support\Desk\DeskTab) renders without the frame: the Desk around
     * it already has the sidebar, top bar, Ask bar, tour and the one live notification channel.
     */
    $inTab = \App\Support\Desk\DeskTab::current();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Enums\Locale::current()->direction() }}" @class(['dk-in-tab' => $inTab])>
    <head>
        @include('partials.head')
    </head>
    <body class="dk-body min-h-screen" @if ($showsInbox && ! $inTab) data-user-id="{{ auth()->id() }}" data-notification-popups="{{ auth()->user()->notification_popups ? 'on' : 'off' }}" data-session-lifetime="{{ (int) config('session.lifetime') }}" @endif @if ($inTab) data-desk-tab-page @endif>
        <div class="dk-app" data-app-shell>
            @auth
                @unless ($inTab)
                    <x-desk.side-nav :shows-inbox="$showsInbox" />
                @endunless
            @endauth

            <div class="dk-main" data-app-main>
                @unless ($inTab)
                    <x-desk.top-bar :shows-inbox="$showsInbox" />
                @endunless

                {{ $slot }}
            </div>
        </div>

        {{-- The Desk icon set as symbols, once per page: every x-desk.icon on the page points at it, so the page carries each drawing once. --}}
        <x-desk.icon-sprite />

        @if ($showsInbox && ! $inTab)
            {{-- The Ask bar's instant index, once per page. --}}
            <x-desk.ask-index />
            <livewire:desk.ask />
            @if (request()->routeIs('dashboard'))
                <livewire:desk.page-panels :route-name="request()->route()?->getName() ?? ''" :page-title="filled($title ?? null) ? __($title) : null" />
            @endif
        @endif

        @unless ($inTab)
            <x-tour.overlay />
        @endunless

        @persist('toast')
            <flux:toast.group position="center">
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @auth
            @unless ($inTab)
                @persist('notification-toasts')
                    <x-notifications.toasts />
                @endpersist

                <x-notifications.install-modal />
            @endunless
        @endauth

        @fluxScripts
    </body>
</html>
