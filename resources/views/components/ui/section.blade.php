@props(['title' => null, 'description' => null, 'padded' => true, 'level' => 2, 'key' => null])

@php
    $headingTag = (int) $level === 3 ? 'h3' : 'h2';
    $headingId = $title !== null && $key !== null ? 'section-'.$key.'-heading' : null;
@endphp

{{-- A borderless group (W-29): an uppercase heading over a divider, then the content. A region that needs a card is an x-ui.panel instead. --}}
<section @if ($key !== null) data-section="{{ $key }}" @endif @if ($headingId !== null) aria-labelledby="{{ $headingId }}" @endif {{ $attributes->class('min-w-0') }}>
    @if ($title !== null || isset($actions))
        <header class="flex flex-wrap items-end justify-between gap-x-4 gap-y-2 border-b border-panel-border pb-2">
            <div class="min-w-0">
                @if ($title !== null)
                    <{{ $headingTag }} @if ($headingId !== null) id="{{ $headingId }}" @endif class="type-label text-secondary-medium dark:text-ink-muted">{{ $title }}</{{ $headingTag }}>
                @endif
                @if ($description !== null)
                    <p class="mt-1 text-sm text-secondary-medium dark:text-ink-muted">{{ $description }}</p>
                @endif
            </div>
            @isset($actions)
                <div class="flex shrink-0 flex-wrap items-center gap-2">{{ $actions }}</div>
            @endisset
        </header>
    @endif

    <div data-section-body @class(['min-w-0', 'pt-4' => $padded])>
        {{ $slot }}
    </div>
</section>
