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
