@props(['title', 'count' => null, 'description' => null, 'headingId' => null])

<<<<<<< HEAD
<header {{ $attributes->class('grid gap-8 border-b border-secondary-soft pb-7 @6xl:grid-cols-[minmax(16rem,.7fr)_minmax(0,1.3fr)] @6xl:items-center dark:border-line') }}>
=======
<header data-page-header {{ $attributes->class('grid gap-8 border-b border-secondary-soft pb-7 @6xl:grid-cols-[minmax(16rem,.7fr)_minmax(0,1.3fr)] @6xl:items-center dark:border-secondary-medium') }}>
>>>>>>> gitlab/security-mai
    <div class="min-w-0">
        <div class="flex items-center gap-2.5">
            <flux:heading :id="$headingId" size="xl" class="text-3xl! font-bold! leading-none!">{{ $title }}</flux:heading>
            @if ($count !== null)
                <span data-count-badge class="inline-flex min-w-7 items-center justify-center rounded-md bg-primary dark:bg-white/10 dark:text-ink px-2 py-0.5 text-xs font-bold tabular-nums text-white">{{ $count }}</span>
            @endif
        </div>
    </div>

    @if (isset($filters) || isset($actions))
        <div
            data-page-header-controls
            @class([
                'flex w-full flex-col gap-3',
                '@7xl:flex-row @7xl:items-end' => isset($filters) && isset($actions),
            ])
        >
            @isset($filters)
                <div data-page-header-filters class="directory-filters grid min-w-0 flex-1 gap-3 @3xl:grid-cols-2 @6xl:grid-cols-3">
                    {{ $filters }}
                </div>
            @endisset

            @isset($actions)
                <div data-page-header-actions class="flex w-fit max-w-full shrink-0 self-end items-end justify-end [&_[data-flux-button]]:w-auto!">
                    {{ $actions }}
                </div>
            @endisset
        </div>
    @endif

    @isset($summary)
        <div class="w-full @6xl:col-span-2">{{ $summary }}</div>
    @endisset
</header>
