<?php

/**
 * Every stylesheet under resources/css, concatenated: app.css imports the others.
 */
function stylesheetSource(): string
{
    $files = glob(dirname(__DIR__, 2).'/resources/css/*.css');
    sort($files);

    return implode("\n", array_map('file_get_contents', $files));
}

/**
 * The project's own Blade views, keyed by path relative to resources/views.
 *
 * @return array<string, string>
 */
function bladeViews(): array
{
    $root = dirname(__DIR__, 2).'/resources/views';
    $views = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

        if (str_ends_with($relative, '.blade.php') && ! str_starts_with($relative, 'vendor/')) {
            $views[$relative] = file_get_contents($file->getPathname());
        }
    }

    return $views;
}

test('the stylesheet exposes the project design tokens', function () {
    preg_match_all(
        '/^\s*(--(?:color|spacing)-[a-z0-9-]+):\s*([^;]+);$/m',
        stylesheetSource(),
        $matches,
    );

    $tokens = array_combine($matches[1], array_map('trim', $matches[2]));

    expect($tokens)->toMatchArray([
        '--color-primary' => '#be1f24',
        '--color-secondary-dark' => '#58595b',
        '--color-secondary-medium' => '#6d6e71',
        '--color-secondary-light' => '#939598',
        '--color-secondary-soft' => '#dcddde',
        '--spacing-ui-2xs' => '0.25rem',
        '--spacing-ui-xs' => '0.5rem',
        '--spacing-ui-sm' => '0.75rem',
        '--spacing-ui-md' => '1rem',
        '--spacing-ui-lg' => '1.5rem',
        '--spacing-ui-xl' => '2rem',
        '--spacing-ui-2xl' => '3rem',
        '--spacing-ui-3xl' => '4rem',
        '--spacing-control' => 'var(--spacing-ui-sm)',
        '--spacing-card' => 'var(--spacing-ui-lg)',
        '--spacing-section' => 'var(--spacing-ui-3xl)',
        '--spacing-page' => 'var(--spacing-ui-xl)',
    ]);
});

test('the stylesheet never selects elements by their translated text', function () {
    expect(stylesheetSource())->not->toMatch('/\[(aria-label|title|placeholder|alt)\s*[~|^$*]?=/');
});

test('dark mode classes in views only use the Graphite tokens', function () {
    $offenders = [];

    foreach (bladeViews() as $path => $source) {
        if (preg_match_all('/dark:(?:[\w\[\]&*:-]+:)?(?:bg|text|border|ring|divide|fill|stroke|outline|placeholder|from|via|to)-(?:zinc|neutral|stone|gray|slate|secondary)-[\w\/]+/', $source, $matches)) {
            $offenders[$path] = array_values(array_unique($matches[0]));
        }
    }

    expect($offenders)->toBe([]);
});

test('views use logical direction utilities so Arabic mirrors correctly', function () {
    $offenders = [];
    $physical = '/(?<=[\s"\'`])((?:[\w@\/\[\]&*.()-]+:)*)(-?(?:ml|mr|pl|pr)-[\w.\[\]\/-]+|text-(?:left|right)|rounded-(?:l|r|tl|tr|bl|br)(?:-[\w\[\]]+)?|border-(?:l|r)(?:-[\w\[\]\/-]+)?|float-(?:left|right))(?=[\s"\'`])/';

    foreach (bladeViews() as $path => $source) {
        preg_match_all($physical, $source, $matches, PREG_SET_ORDER);

        foreach ($matches as [$class, $variants]) {
            if (! preg_match('/(^|:)(rtl|ltr):/', $variants)) {
                $offenders[$path][] = $class;
            }
        }
    }

    expect($offenders)->toBe([]);
});

test('neither the views nor an included Flux component render a Flux component the stylesheet skips', function () {
    preg_match_all('#@source not \'[^\']*/stubs/resources/views/flux/([a-z-]+)#', stylesheetSource(), $matches);

    $skipped = $matches[1];
    $offenders = [];
    $sources = bladeViews();

    // Flux components render each other, so the stubs the stylesheet still scans count too.
    $stubs = dirname(__DIR__, 2).'/vendor/livewire/flux/stubs/resources/views/flux';

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stubs, FilesystemIterator::SKIP_DOTS)) as $file) {
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($stubs) + 1));
        $component = preg_replace('/(\/.*|\.blade\.php)$/', '', $relative);

        if (! in_array($component, $skipped, true)) {
            $sources['flux-stub:'.$relative] = file_get_contents($file->getPathname());
        }
    }

    foreach ($sources as $path => $source) {
        foreach ($skipped as $component) {
            if (preg_match('/<flux:'.preg_quote($component, '/').'[\s.>\/]/', $source)) {
                $offenders[$path][] = $component;
            }
        }
    }

    expect($skipped)->not->toBeEmpty()
        ->and($offenders)->toBe([]);
});

test('arbitrary letter-spacing in views is a value the Arabic reset covers', function () {
    $offenders = [];

    foreach (bladeViews() as $path => $source) {
        preg_match_all('/tracking-(\[[^\]]*\]|\([^)]*\))/', $source, $matches);

        foreach ($matches[1] as $value) {
            // typography.css resets tracking-[0…] and tracking-[.…] under :lang(ar); negative values are tight figures, left alone.
            if (! preg_match('/^\[(0|\.|-)/', $value)) {
                $offenders[$path][] = 'tracking-'.$value;
            }
        }
    }

    expect($offenders)->toBe([]);
});

test('views reference colours through design tokens rather than raw hex', function () {
    // Standalone documents that never load app.css: the notification image templates
    // and the browser theme-color metas, which cannot read CSS variables.
    $standalone = ['notifications/banner.blade.php', 'notifications/tile.blade.php', 'partials/head.blade.php'];
    $offenders = [];

    foreach (bladeViews() as $path => $source) {
        if (in_array($path, $standalone, true)) {
            continue;
        }

        $markup = preg_replace('/\{\{--.*?--\}\}/s', '', $source);

        if (preg_match_all('/(?<![\w&])#[0-9a-fA-F]{6}\b|(?<![\w&])#[0-9a-fA-F]{3}\b(?![\w-])/', $markup, $matches)) {
            $offenders[$path] = array_values(array_unique($matches[0]));
        }
    }

    expect($offenders)->toBe([]);
});
