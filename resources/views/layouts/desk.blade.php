{{-- The Tracking Desk frame: the top bar from layouts/app/sidebar, then the page on the Desk ground at the Desk's width. --}}
<x-layouts::app.sidebar :title="$title ?? null">
    <div class="dk-scope">
        <main class="dk-wrap" id="main" data-desk-page>
            {{ $slot }}
        </main>
    </div>
</x-layouts::app.sidebar>
