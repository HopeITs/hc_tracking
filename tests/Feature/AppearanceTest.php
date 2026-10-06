<?php

use App\Models\User;

/**
 * The opening <html> tag of a rendered page.
 */
function openingHtmlTag(string $html): string
{
    preg_match('/<html\b[^>]*>/i', $html, $matches);

    return $matches[0] ?? '';
}

test('the application layout leaves light or dark to the stored or system appearance', function () {
    $this->actingAs(User::factory()->role('Team Member')->create());

    $html = $this->get(route('dashboard'))->assertOk()->getContent();

    expect(openingHtmlTag($html))->toBe('<html lang="en" dir="ltr">');
});

test('the sign-in layout leaves light or dark to the stored or system appearance', function () {
    $html = $this->get(route('login'))->assertOk()->getContent();

    expect(openingHtmlTag($html))->toBe('<html lang="en" dir="ltr">');
});

test('error pages leave light or dark to the stored or system appearance', function () {
    $html = (string) $this->view('errors.404');

    expect(openingHtmlTag($html))->toBe('<html lang="en" dir="ltr">');
});

test('the desktop top header switches light and dark from one button, with no menu to choose from', function () {
    $this->actingAs(User::factory()->role('Team Member')->create());

    $response = $this->get(route('dashboard'))->assertOk();

    $document = new DOMDocument;
    $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);

    $header = $xpath->query('//*[@data-desktop-navigation-header]')->item(0);
    $toggles = $xpath->query('.//*[@data-theme-toggle]', $header);

    expect($toggles)->toHaveCount(1)
        ->and($toggles->item(0)->nodeName)->toBe('button')
        ->and($toggles->item(0)->getAttribute('aria-label'))->toBe('Appearance')
        ->and($toggles->item(0)->getAttribute('x-on:click'))->toBe('hcToggleAppearance()')
        ->and($toggles->item(0)->hasAttribute('data-top-bar-action'))->toBeTrue()
        ->and($xpath->query('ancestor::ui-dropdown', $toggles->item(0)))->toHaveCount(0);
});

test('the theme button is labelled in arabic', function () {
    $this->actingAs(User::factory()->role('Team Member')->create(['locale' => 'ar']));

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('aria-label="المظهر"', false);
});
