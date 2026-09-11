@props(['title' => null, 'subtitle' => null, 'count' => null])

<section {{ $attributes->class('overflow-hidden rounded-lg border border-secondary-light bg-white dark:border-secondary-medium dark:bg-secondary-dark') }}>
    @if ($title !== null || isset($toolbar))
        <div class="flex min-h-10 flex-wrap items-center justify-between gap-2 border-b border-secondary-soft px-3.5 py-2 dark:border-secondary-medium">
            <div class="min-w-0">
                @if ($title !== null)
                    <div class="flex items-center gap-2">
                        <p class="truncate text-sm font-semibold text-secondary-dark dark:text-white">{{ $title }}</p>
                        @if ($count !== null)
                            <span class="inline-flex min-w-6 items-center justify-center rounded-md border border-secondary-light px-1.5 py-0.5 text-[11px] font-semibold tabular-nums text-secondary-medium dark:text-secondary-soft">{{ $count }}</span>
                        @endif
                    </div>
                @endif
                @if ($subtitle !== null)
                    <p class="truncate text-xs text-secondary-medium dark:text-secondary-soft">{{ $subtitle }}</p>
                @endif
            </div>
            {{ $toolbar ?? '' }}
        </div>
    @endif

    {{ $slot }}

    @isset($footer)
        <div class="border-t border-secondary-soft px-3 pb-3 dark:border-secondary-medium">{{ $footer }}</div>
    @endisset
</section>
