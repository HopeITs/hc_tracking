<x-layouts::app.sidebar :title="$title ?? null">
    {{-- Pages not yet moved onto the Desk keep their own frame; the Desk itself uses layouts::desk. --}}
    <main class="flex-1 bg-white p-6 text-secondary-dark lg:p-8 dark:bg-surface-0 dark:text-ink">
        <div
            class="mx-auto flex min-h-full w-full max-w-[90rem] min-[150rem]:max-w-[110rem] flex-1 flex-col pt-8"
            data-app-page-container
        >
            {{ $slot }}
        </div>
    </main>

    <flux:footer class="border-t border-secondary-soft bg-white py-4 text-center dark:border-line dark:bg-surface-1">
        <p class="text-xs text-secondary-medium dark:text-ink-muted">
            {{ __('authentication.copyright_notice', ['year' => now()->year]) }}
        </p>
    </flux:footer>
</x-layouts::app.sidebar>
