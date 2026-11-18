@props(['breadcrumbs' => [], 'title', 'subtitle' => null, 'titleClass' => null, 'unread' => false, 'back' => null])

<header data-detail-header {{ $attributes->class('flex flex-col gap-3') }}>
    @if ($back !== null)
        <div>
            <a class="dk-back" href="{{ $back }}" wire:navigate data-detail-back><x-desk.icon name="back" class="dk-flip" />{{ __('common.back') }}</a>
        </div>
    @endif

    @if ($breadcrumbs !== [])
        <nav aria-label="{{ __('ui.breadcrumb') }}" class="dk-crumb mt-0!">
            @foreach ($breadcrumbs as $index => $crumb)
                @if ($index > 0)
                    <span aria-hidden="true">›</span>
                @endif

                @if (isset($crumb['url']))
                    <a href="{{ $crumb['url'] }}" wire:navigate>{{ $crumb['label'] }}</a>
                @else
                    <span @if ($loop->last) aria-current="page" @endif>{{ $crumb['label'] }}</span>
                @endif
            @endforeach
        </nav>
    @endif

    <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
        <div class="min-w-0 sm:me-auto">
            <div class="flex min-w-0 items-center gap-2">
                <flux:heading size="lg" class="page-title! {{ $titleClass }}">{{ $title }}</flux:heading>
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
        @isset($actions)
            {{-- Actions sit at the end, also when a narrow screen drops them under the title. --}}
            <div data-detail-header-actions class="flex min-w-0 flex-wrap items-center justify-end gap-2 [&>.flex]:justify-end">{{ $actions }}</div>
        @endisset
    </div>

    @isset($summary)
        <div>{{ $summary }}</div>
    @endisset
</header>
