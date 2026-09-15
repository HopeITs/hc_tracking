@props(['title', 'count' => null, 'description' => null, 'headingId' => null])

<header {{ $attributes->class('flex flex-col gap-3 border-b border-secondary-soft pb-4 dark:border-secondary-medium') }}>
    <div class="flex flex-col gap-3 @6xl/directory:flex-row @6xl/directory:items-end">
        <div class="min-w-48 @6xl/directory:me-auto">
            <div class="flex items-center gap-2">
                <flux:heading :id="$headingId" size="lg">{{ $title }}</flux:heading>
                @if ($count !== null)
                    <span data-count-badge class="inline-flex min-w-7 items-center justify-center rounded-md border border-secondary-light px-2 py-0.5 text-xs font-semibold tabular-nums text-secondary-medium dark:text-secondary-soft">{{ $count }}</span>
                @endif
            </div>
            @if ($description !== null)
                <p class="text-sm text-secondary-medium dark:text-secondary-soft">{{ $description }}</p>
            @endif
        </div>

        @isset($filters)
            <div class="grid w-full gap-2 @sm/directory:grid-cols-2 @6xl/directory:max-w-[62rem]">{{ $filters }}</div>
        @endisset

        {{ $actions ?? '' }}
    </div>

    @isset($summary)
        <div class="w-full">{{ $summary }}</div>
    @endisset
</header>
