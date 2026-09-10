<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main class="bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white">
        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
