<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main class="bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink">
        <div
            class="mx-auto flex min-h-full w-full max-w-[90rem] min-[150rem]:max-w-[110rem] flex-1 flex-col pt-8"
            data-app-page-container
        >
            {{ $slot }}
        </div>
    </flux:main>

    <flux:footer class="border-t border-secondary-soft bg-white py-4 text-center dark:border-line dark:bg-surface-1">
        <p class="text-xs text-secondary-medium dark:text-ink-muted">
            {{ __('authentication.copyright_notice', ['year' => now()->year]) }}
        </p>
    </flux:footer>
</x-layouts::app.sidebar>
