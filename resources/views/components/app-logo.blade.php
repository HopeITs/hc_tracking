@props([
    'sidebar' => false,
])

@php
    $brandName = config('app.name', 'HC Tracking');
@endphp

{{--
    The brand is rendered without a `name`, because the wordmark already carries
    it: passing one would print "HC Tracking" in text beside a logo that says
    the same thing.

    Collapse and theme are kept on separate elements on purpose. The outer spans
    decide wordmark-versus-mark, and the images inside them decide red-versus-
    white, so a collapse rule never competes with `dark:block` on one element.
--}}
@if ($sidebar)
    {{--
        Collapsed, app.css starts the mark on the same centre line as the nav
        icons below it. The collapse toggle
        floats separately on the rail's outer edge, outside the brand's box.
    --}}
    <flux:sidebar.brand {{ $attributes }}>
        <x-slot name="logo" class="h-auto! min-w-0! justify-start! rounded-none! [:where(&)]:h-auto">
            {{--
                On desktop the wordmark and mark share one grid cell and app.css
                crossfades them as the rail collapses. The wordmark only fades, so
                it keeps naming the link and the mark stays decorative. The mark
                is `hidden` here for the mobile drawer, which never collapses.
            --}}
            <span data-sidebar-logo>
                <span data-sidebar-logo-wordmark>
                    <img src="{{ asset('logo-tracking.svg') }}" alt="{{ $brandName }}" class="h-9 w-auto dark:hidden" data-no-dim />
                    <img src="{{ asset('logo-tracking-white.svg') }}" alt="{{ $brandName }}" class="hidden h-9 w-auto dark:block" data-no-dim />
                </span>

                <span class="hidden" data-sidebar-logo-mark>
                    <img src="{{ asset('logo-tracking-icon.svg') }}" alt="" class="size-9 shrink-0" data-no-dim />
                </span>
            </span>
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand {{ $attributes }}>
        <x-slot name="logo" class="h-auto! min-w-0! justify-start! rounded-none! [:where(&)]:h-auto">
            <img src="{{ asset('logo-tracking.svg') }}" alt="{{ $brandName }}" class="h-7 w-auto dark:hidden" data-no-dim />
            <img src="{{ asset('logo-tracking-white.svg') }}" alt="{{ $brandName }}" class="hidden h-7 w-auto dark:block" data-no-dim />
        </x-slot>
    </flux:brand>
@endif
