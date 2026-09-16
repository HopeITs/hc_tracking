@props(['breadcrumbs' => [], 'title', 'subtitle' => null, 'titleClass' => null])

<header {{ $attributes->class('flex flex-col gap-3 border-b border-secondary-soft pb-4 dark:border-secondary-medium') }}>
    @if ($breadcrumbs !== [])
        <nav aria-label="{{ __('ui.breadcrumb') }}" class="flex flex-wrap items-center gap-1.5 text-xs text-secondary-medium dark:text-secondary-soft">
            @foreach ($breadcrumbs as $index => $crumb)
                @if ($index > 0)
                    <span aria-hidden="true" class="text-secondary-light">/</span>
                @endif

                @if (isset($crumb['url']))
                    <a href="{{ $crumb['url'] }}" wire:navigate class="rounded font-medium hover:text-secondary-dark dark:hover:text-white">{{ $crumb['label'] }}</a>
                @else
                    <span aria-current="page" class="font-semibold text-secondary-dark dark:text-white">{{ $crumb['label'] }}</span>
                @endif
            @endforeach
        </nav>
    @endif

    <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
        <div class="min-w-0 sm:me-auto">
            <flux:heading size="lg" class="{{ $titleClass }}">{{ $title }}</flux:heading>
            @if ($subtitle !== null)
                <p class="text-sm text-secondary-medium dark:text-secondary-soft">{{ $subtitle }}</p>
            @endif
            @isset($meta)
                <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-xs text-secondary-medium dark:text-secondary-soft">{{ $meta }}</div>
            @endisset
        </div>
        {{ $actions ?? '' }}
    </div>

    @isset($summary)
        <div>{{ $summary }}</div>
    @endisset
</header>
