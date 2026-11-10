<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="csrf-token" content="{{ csrf_token() }}" />
<meta name="desk-home" content="{{ parse_url(route('dashboard'), PHP_URL_PATH) ?: '/' }}" />
@auth
    <meta name="desk-items-url" content="{{ route('desk.items.toggle') }}" />
@endauth

<title>
    {{ filled($title ?? null) ? __($title).' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="{{ asset('logo-tracking-icon.svg') }}" type="image/svg+xml">
<link rel="apple-touch-icon" href="{{ asset('app-icons/apple-touch-icon.png') }}">
<link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
<meta name="theme-color" content="#dcddde" media="(prefers-color-scheme: light)" data-theme-color="light">
<meta name="theme-color" content="#1c1d20" media="(prefers-color-scheme: dark)" data-theme-color="dark">
<meta name="application-name" content="HC Tracking">
<meta name="apple-mobile-web-app-title" content="HC Tracking">
<meta name="mobile-web-app-capable" content="yes">

{{-- Inside a Desk tab (an iframe on My desk) the page drops its own frame before first paint, even when the server could not tell (resources/js/desk-tab-child.js). --}}
<script>try { if (window.top !== window.self && window.parent.location.origin === window.location.origin && window.parent.document.querySelector('[data-desk-tabs]')) { document.documentElement.classList.add('dk-in-tab'); } } catch (e) {}</script>

{{-- Restores the sidebar's expanded/rail choice before first paint (resources/js/side-nav.js). --}}
<script>try { if (localStorage.getItem('hc-nav') === 'open') { document.documentElement.classList.add('dk-nav-open'); } } catch (e) {}</script>

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
