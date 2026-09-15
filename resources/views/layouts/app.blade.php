<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main class="bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white">
        <div
            class="mx-auto flex min-h-full w-full max-w-[90rem] flex-1 flex-col pt-8"
            data-app-page-container
        >
            {{ $slot }}
        </div>
    </flux:main>
</x-layouts::app.sidebar>
