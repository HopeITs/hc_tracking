<?php

test('the stylesheet exposes the project design tokens', function () {
    $stylesheet = file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');

    preg_match_all(
        '/^\s*(--(?:color|spacing)-[a-z0-9-]+):\s*([^;]+);$/m',
        $stylesheet,
        $matches,
    );

    $tokens = array_combine($matches[1], array_map('trim', $matches[2]));

    expect($tokens)->toMatchArray([
        '--color-primary' => '#be1f24',
        '--color-secondary-dark' => '#58595b',
        '--color-secondary-medium' => '#6d6e71',
        '--color-secondary-light' => '#939598',
        '--color-secondary-soft' => '#dcddde',
        '--spacing-2xs' => '0.25rem',
        '--spacing-xs' => '0.5rem',
        '--spacing-sm' => '0.75rem',
        '--spacing-md' => '1rem',
        '--spacing-lg' => '1.5rem',
        '--spacing-xl' => '2rem',
        '--spacing-2xl' => '3rem',
        '--spacing-3xl' => '4rem',
        '--spacing-control' => 'var(--spacing-sm)',
        '--spacing-card' => 'var(--spacing-lg)',
        '--spacing-section' => 'var(--spacing-3xl)',
        '--spacing-page' => 'var(--spacing-xl)',
    ]);
});
