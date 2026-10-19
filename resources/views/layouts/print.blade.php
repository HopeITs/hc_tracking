<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Enums\Locale::current()->direction() }}">
    <head>
        @include('partials.head')
    </head>
    {{-- A plain page for the browser's print-to-PDF: no navigation, the toolbar hides on paper. --}}
    <body class="min-h-screen bg-white text-secondary-dark antialiased dark:bg-surface-0 dark:text-ink print:bg-white print:text-black">
        <main class="mx-auto max-w-5xl px-4 py-6 sm:px-8 print:max-w-none print:p-0">
            {{ $slot }}
        </main>
    </body>
</html>
