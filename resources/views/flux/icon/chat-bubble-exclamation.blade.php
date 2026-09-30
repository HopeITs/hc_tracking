{{-- A Heroicons chat bubble with an exclamation mark: the familiar "report a problem" feedback icon. Outline is stroked, the other variants are filled with the mark cut out. --}}

@props([
    'variant' => 'outline',
])

@php
    $classes = Flux::classes('shrink-0')->add(
        match ($variant) {
            'outline' => '[:where(&)]:size-6',
            'solid' => '[:where(&)]:size-6',
            'mini' => '[:where(&)]:size-5',
            'micro' => '[:where(&)]:size-4',
        },
    );
@endphp

@if ($variant === 'outline')
    <svg
        {{ $attributes->class($classes) }}
        data-flux-icon
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.5"
        aria-hidden="true"
        data-slot="icon"
    >
        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.76c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.076-4.076a1.526 1.526 0 0 1 1.037-.443 48.282 48.282 0 0 0 5.68-.494c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" />
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.75v3.5m0 2.5h.008v.008H12v-.008Z" />
    </svg>
@else
    <svg
        {{ $attributes->class($classes) }}
        data-flux-icon
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 20 20"
        fill="currentColor"
        aria-hidden="true"
        data-slot="icon"
    >
        <path fill-rule="evenodd" clip-rule="evenodd" d="M3.43 2.524A41.29 41.29 0 0 1 10 2c2.236 0 4.43.18 6.57.524 1.437.231 2.43 1.49 2.43 2.902v5.148c0 1.413-.993 2.67-2.43 2.902a41.102 41.102 0 0 1-3.55.414c-.28.02-.521.18-.643.413l-1.712 3.293a.75.75 0 0 1-1.33 0l-1.713-3.293a.783.783 0 0 0-.642-.413 41.108 41.108 0 0 1-3.55-.414C1.993 13.245 1 11.986 1 10.574V5.426c0-1.413.993-2.67 2.43-2.902ZM10 4.25a.75.75 0 0 1 .75.75v3.25a.75.75 0 0 1-1.5 0V5a.75.75 0 0 1 .75-.75Zm0 7a.9.9 0 1 0 0-1.8.9.9 0 0 0 0 1.8Z" />
    </svg>
@endif
