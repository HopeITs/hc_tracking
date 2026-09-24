<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="csrf-token" content="{{ csrf_token() }}" />

<title>
    {{ filled($title ?? null) ? __($title).' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="{{ asset('logo-tracking-icon.svg') }}" type="image/svg+xml">
<link rel="apple-touch-icon" href="{{ asset('app-icons/apple-touch-icon.png') }}">
<link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
<meta name="theme-color" content="#dcddde" media="(prefers-color-scheme: light)" data-theme-color="light">
<meta name="theme-color" content="#58595b" media="(prefers-color-scheme: dark)" data-theme-color="dark">
<meta name="application-name" content="HC Tracking">
<meta name="apple-mobile-web-app-title" content="HC Tracking">
<meta name="mobile-web-app-capable" content="yes">

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
