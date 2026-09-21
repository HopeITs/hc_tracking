@props(['label', 'count', 'colorClass' => 'bg-secondary-light', 'storageKey'])

<div
    x-data="{ open: true }"
    x-init="try {
        const stored = localStorage.getItem('{{ $storageKey }}');
        if (stored !== null) { open = stored === 'true'; }
        $watch('open', (value) => { try { localStorage.setItem('{{ $storageKey }}', value); } catch (e) {} });
    } catch (e) {}"
>
    <div class="sticky top-0 z-10 flex items-center gap-2 border-b border-secondary-soft bg-white/95 px-3.5 py-2 backdrop-blur dark:border-secondary-medium dark:bg-secondary-dark/95">
        <button
            type="button"
            x-on:click="open = !open"
            :aria-expanded="open.toString()"
            class="inline-flex size-6 shrink-0 cursor-pointer items-center justify-center rounded text-secondary-medium hover:bg-secondary-soft dark:text-secondary-soft dark:hover:bg-secondary-medium"
        >
            <flux:icon.chevron-right variant="micro" class="size-3.5 rtl:-scale-x-100" x-bind:class="open && 'rotate-90 rtl:rotate-90'" style="transition: transform 150ms" />
        </button>
        <span class="size-2 shrink-0 rounded-full {{ $colorClass }}" aria-hidden="true"></span>
        <p class="truncate text-xs font-bold tracking-wide text-secondary-dark uppercase dark:text-white">{{ $label }}</p>
        <span class="inline-flex min-w-5 shrink-0 items-center justify-center rounded-full bg-secondary-soft px-1.5 py-0.5 text-[11px] font-semibold tabular-nums text-secondary-dark dark:bg-secondary-medium dark:text-white">{{ $count }}</span>
        <div class="ms-auto shrink-0">{{ $actions ?? '' }}</div>
    </div>
    <div x-show="open" x-collapse>
        {{ $slot }}
    </div>
</div>
