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
    decide wordmark-versus-icon, and the images inside them decide red-versus-
    white. Combining both on one element would put `dark:block` and
    `in-data-flux-sidebar-collapsed-desktop:hidden` at equal specificity, where
    the winner is decided by stylesheet order rather than by intent.
--}}
@if ($sidebar)
    {{--
        Collapsed, the brand stays centred (Flux's own default for
        `flux:sidebar.brand`: a `w-10` box with `justify-center`), matching
        the same box model `flux:sidebar.item` uses for its icons — that's
        what lines the logo mark up with the nav icons below it. The collapse
        toggle floats separately, straddling the rail's outer edge (`-end-4
        top-[15.5px]`), outside the brand's box, so nothing forces the mark off-centre
        anymore.
    --}}
    <flux:sidebar.brand {{ $attributes }}>
        <x-slot name="logo" class="h-auto! min-w-0! justify-start! rounded-none! [:where(&)]:h-auto">
            <span class="in-data-flux-sidebar-collapsed-desktop:hidden">
                <img src="{{ asset('logo-tracking.svg') }}" alt="{{ $brandName }}" class="h-9 w-auto dark:hidden" />
                <img src="{{ asset('logo-tracking-white.svg') }}" alt="{{ $brandName }}" class="hidden h-9 w-auto dark:block" />
            </span>

            <span class="hidden in-data-flux-sidebar-collapsed-desktop:block">
                <img src="{{ asset('logo-tracking-icon.svg') }}" alt="{{ $brandName }}" class="size-9 shrink-0" />
            </span>
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand {{ $attributes }}>
        <x-slot name="logo" class="h-auto! min-w-0! justify-start! rounded-none! [:where(&)]:h-auto">
            <img src="{{ asset('logo-tracking.svg') }}" alt="{{ $brandName }}" class="h-7 w-auto dark:hidden" />
            <img src="{{ asset('logo-tracking-white.svg') }}" alt="{{ $brandName }}" class="hidden h-7 w-auto dark:block" />
        </x-slot>
    </flux:brand>
@endif
