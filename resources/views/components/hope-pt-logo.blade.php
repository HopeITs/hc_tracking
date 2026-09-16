@props(['label' => 'Hope PT'])

<svg
    {{ $attributes->class('hope-pt-logo') }}
    viewBox="0 0 380 130"
    xmlns="http://www.w3.org/2000/svg"
    role="img"
    aria-label="{{ $label }}"
>
    <style>
        .hope-pt-logo-script { fill: var(--color-secondary-medium); }
        .dark .hope-pt-logo-script { fill: var(--color-secondary-soft); }
    </style>
    <text x="0" y="72" font-family="Arial, Helvetica, sans-serif" font-weight="900" font-size="66" letter-spacing="-2.5" fill="var(--color-primary)">HOPE</text>
    <text x="2" y="99" font-family="Arial, Helvetica, sans-serif" font-weight="700" font-size="16" letter-spacing="4.5" fill="var(--color-primary)">CENTER</text>
    <text x="212" y="76" class="hope-pt-logo-script" font-family="'Alex Brush', cursive" font-size="64">PT</text>
</svg>
