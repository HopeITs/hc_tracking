@props(['breadcrumbs' => [], 'title', 'subtitle' => null, 'titleClass' => null, 'unread' => false])

<<<<<<< HEAD
<header {{ $attributes->class('flex flex-col gap-3 border-b border-secondary-soft pb-4 dark:border-line') }}>
=======
<header data-detail-header {{ $attributes->class('flex flex-col gap-3 border-b border-secondary-soft pb-4 dark:border-secondary-medium') }}>
>>>>>>> gitlab/security-mai
    @if ($breadcrumbs !== [])
        <nav aria-label="{{ __('ui.breadcrumb') }}" class="flex flex-wrap items-center gap-1.5 text-xs text-secondary-medium dark:text-ink-muted">
            @foreach ($breadcrumbs as $index => $crumb)
                @if ($index > 0)
                    <span aria-hidden="true" class="text-secondary-light">/</span>
                @endif

                @if (isset($crumb['url']))
                    <a href="{{ $crumb['url'] }}" wire:navigate class="rounded font-medium hover:text-secondary-dark dark:hover:text-ink">{{ $crumb['label'] }}</a>
                @else
                    <span aria-current="page" class="font-semibold text-secondary-dark dark:text-ink">{{ $crumb['label'] }}</span>
                @endif
            @endforeach
        </nav>
    @endif

    <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
        <div class="min-w-0 sm:me-auto">
            <div class="flex min-w-0 items-center gap-2">
                <flux:heading size="lg" class="{{ $titleClass }}">{{ $title }}</flux:heading>
                @if ($unread)
                    <span class="size-2.5 shrink-0 rounded-full bg-primary dark:bg-ink" data-unread-dot title="{{ __('notifications.has_unread') }}"><span class="sr-only">{{ __('notifications.has_unread') }}</span></span>
                @endif
            </div>
            @if ($subtitle !== null)
                <p class="text-sm text-secondary-medium dark:text-ink-muted">{{ $subtitle }}</p>
            @endif
            @isset($meta)
                <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-xs text-secondary-medium dark:text-ink-muted">{{ $meta }}</div>
            @endisset
        </div>
        {{ $actions ?? '' }}
    </div>

    @isset($summary)
        <div>{{ $summary }}</div>
    @endisset
</header>
