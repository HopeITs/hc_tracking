{{-- The Tracking Desk frame: the top bar from layouts/app/sidebar, then the page on the Desk ground at the Desk's width, then the copyright line. --}}
<x-layouts::app.sidebar :title="$title ?? null">
    <div class="dk-scope">
        <main class="dk-wrap" id="main" data-desk-page>
            {{ $slot }}
        </main>

        <footer class="dk-foot" data-desk-footer>
            <p>{{ __('authentication.copyright_notice', ['year' => now()->year]) }}</p>
        </footer>
    </div>
</x-layouts::app.sidebar>
