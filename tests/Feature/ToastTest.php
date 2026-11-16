<?php

use Illuminate\Support\Facades\Blade;

function renderToastGroup(): string
{
    return Blade::render('<flux:toast.group position="bottom center"><flux:toast /></flux:toast.group>');
}

test('the toast keeps every hook the Flux script fills', function () {
    $html = renderToastGroup();

    expect($html)->toContain('data-flux-toast-dialog')
        ->toContain('<slot name="heading"></slot>')
        ->toContain('<slot name="text"></slot>')
        ->toContain('<template name="link">')
        ->toContain('<template name="action">')
        ->toContain('data-flux-toast-action-button')
        ->toContain('data-flux-toast-action-link')
        ->toContain('data-flux-toast-action-label')
        ->toContain('data-flux-loading-indicator')
        ->toContain('<ui-close');
});

test('the toast renders the app design with a badge for every variant and a labelled close button', function () {
    $html = renderToastGroup();

    expect($html)->toContain('hc-toast-card')
        ->toContain('data-icon="success"')
        ->toContain('data-icon="alert"')
        ->toContain('data-icon="info"')
        ->toContain('hc-toast-timer')
        ->toContain('aria-label="Close"');
});

test('a toast sent with the Flux default lives by its variant, and an error stays until closed', function () {
    $html = renderToastGroup();

    expect($html)->toContain('[undefined, 5000].includes(detail.duration) ? ({ danger: 0, warning: 6000 }[detail.dataset?.variant] ?? 4000) : Number(detail.duration)')
        ->toContain("setAttribute('data-life', String(life))")
        ->toContain("style.setProperty('--hc-toast-life', life + 'ms')");
});

test('the timer line runs for the toast\'s own life and is hidden on a toast that stays', function () {
    $css = file_get_contents(resource_path('css/flux-overrides.css'));

    preg_match('/\.hc-toast-timer \{.*?\n\}/s', $css, $timer);

    expect($timer[0])->toContain('animation: hc-toast-timer var(--hc-toast-life, 4000ms) linear forwards')
        ->toContain(".hc-toast[data-life='0'] & {");
});

test('toasts sit at the bottom centre and never cover or dim the page', function () {
    $css = file_get_contents(resource_path('css/flux-overrides.css'));
    preg_match('/\/\*\n \* Flux toasts.*?@keyframes hc-toast-pop/s', $css, $toastCss);

    foreach (['layouts/app/sidebar.blade.php', 'layouts/auth/simple.blade.php'] as $layout) {
        expect(file_get_contents(resource_path('views/'.$layout)))->toContain('<flux:toast.group position="bottom center">');
    }

    expect($toastCss[0])->not->toContain('100vmax')
        ->not->toContain("position='center'");
});

test('esc closes the toast before a modal underneath can take it', function () {
    expect(renderToastGroup())->toContain('x-on:keydown.escape.window.capture=');
});

test('toasts use only the site palette: no green, amber or blue', function () {
    $css = file_get_contents(resource_path('css/flux-overrides.css'));
    preg_match('/\/\*\n \* Flux toasts.*?@keyframes hc-toast-pop/s', $css, $toastCss);

    $sources = [
        $toastCss[0],
        file_get_contents(resource_path('views/flux/toast/index.blade.php')),
        file_get_contents(resource_path('views/components/notifications/toasts.blade.php')),
    ];

    foreach ($sources as $source) {
        expect($source)->not->toContain('status-good')
            ->not->toContain('status-warn')
            ->not->toContain('status-active');
    }
});
