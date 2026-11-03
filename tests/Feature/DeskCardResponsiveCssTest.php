<?php

/**
 * The Desk stylesheets, every comment removed, as one string.
 */
function deskStylesheets(): string
{
    $combined = '';

    foreach (glob(resource_path('css/desk*.css')) as $stylesheet) {
        $combined .= preg_replace('#/\*.*?\*/#s', '', file_get_contents($stylesheet));
    }

    return $combined;
}

test('desk stylesheets never select on the inline --span style', function () {
    expect(deskStylesheets())->not->toContain('[style*="--span');
});

test('needs you moves its actions under the text on a narrow card, except at two rows', function () {
    $css = deskStylesheets();

    expect($css)
        ->toContain('.dk-panel.dk-needs:not([data-rows="2"]) .dk-pb .dk-items > li { grid-template-columns: 12px minmax(0, 1fr) !important; }')
        ->toContain('.dk-panel.dk-needs:not([data-rows="2"]) .dk-pb .dk-items .dk-acts { grid-column: 2 !important;');
});

test('line-clamped titles stay out of the arabic plaintext rule, which would drop their ellipsis', function () {
    preg_match('/\[dir="rtl"\] \.dk-panel :is\(\.dk-fp-n[^{]*\{ unicode-bidi: plaintext;/', deskStylesheets(), $plaintextRule);

    expect($plaintextRule[0])->not->toContain('.dk-focus-title')
        ->not->toContain('.dk-wt-t')
        ->not->toContain(' .dk-rv-m,');
});

test('monthly reports hide the oldest months on a narrow card so the name stays readable', function () {
    $css = deskStylesheets();

    expect($css)->toMatch('/@container pb \(max-width: 639px\)\{ \.dk-mx2-r>:not\(:first-child\):nth-last-child\(n\+12\)\{ display:none \} \}/')
        ->and($css)->toMatch('/@container pb \(max-width: 293px\)\{ \.dk-mx2-r>:not\(:first-child\):nth-last-child\(n\+4\)\{ display:none \} \}/')
        ->and($css)->not->toContain('repeat(var(--months)');
});
