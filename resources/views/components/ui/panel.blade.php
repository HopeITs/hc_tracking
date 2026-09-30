@props(['title' => null, 'subtitle' => null, 'count' => null])

<<<<<<< HEAD
<section {{ $attributes->class('overflow-hidden rounded-xl border border-secondary-soft bg-white shadow-[0_1px_2px_rgba(16,24,40,0.04),0_1px_3px_rgba(16,24,40,0.06)] dark:border-transparent dark:bg-surface-1 dark:shadow-raised') }}>
=======
<section data-panel {{ $attributes->class('overflow-hidden rounded-xl border border-secondary-soft bg-white shadow-[0_1px_2px_rgba(16,24,40,0.04),0_1px_3px_rgba(16,24,40,0.06)] dark:border-secondary-medium dark:bg-secondary-dark dark:shadow-none') }}>
>>>>>>> gitlab/security-mai
    @if ($title !== null || isset($toolbar))
        <div class="flex min-h-12 flex-wrap items-center justify-between gap-2 border-b border-secondary-soft px-4 py-3 dark:border-line">
            <div class="min-w-0">
                @if ($title !== null)
                    <div class="flex items-center gap-2">
                        <p class="truncate text-base font-bold text-secondary-dark dark:text-ink">{{ $title }}</p>
                        @if ($count !== null)
                            <span class="inline-flex min-w-6 items-center justify-center rounded-full bg-secondary-soft px-2 py-0.5 text-[11px] font-semibold tabular-nums text-secondary-dark dark:bg-surface-2 dark:text-ink">{{ $count }}</span>
                        @endif
                    </div>
                @endif
                @if ($subtitle !== null)
                    <p class="truncate text-xs text-secondary-medium dark:text-ink-muted">{{ $subtitle }}</p>
                @endif
            </div>
            {{ $toolbar ?? '' }}
        </div>
    @endif

    {{ $slot }}

    @isset($footer)
        <div class="border-t border-secondary-soft px-4 pb-3 dark:border-line">{{ $footer }}</div>
    @endisset
</section>
