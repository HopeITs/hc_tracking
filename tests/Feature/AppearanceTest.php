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

test('the layouts no route uses leave light or dark to the stored or system appearance', function (string $layout) {
    $this->actingAs(User::factory()->create());

    $html = (string) $this->blade("<x-layouts::{$layout}>Body</x-layouts::{$layout}>");

    expect(openingHtmlTag($html))->toBe('<html lang="en" dir="ltr">');
})->with(['app.header', 'auth.card', 'auth.split']);

test('error pages leave light or dark to the stored or system appearance', function () {
    $html = (string) $this->view('errors.404');

    expect(openingHtmlTag($html))->toBe('<html lang="en" dir="ltr">');
});

test('the desktop top header offers a light, dark and system appearance menu', function () {
    $this->actingAs(User::factory()->role('Team Member')->create());

    $response = $this->get(route('dashboard'))->assertOk();

    $document = new DOMDocument;
    $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);

    $header = $xpath->query('//*[@data-desktop-navigation-header]')->item(0);
    $triggers = $xpath->query('.//*[@data-theme-toggle]', $header);
    $options = collect($xpath->query('.//*[@data-appearance-option]', $header));

    expect($triggers)->toHaveCount(1)
        ->and($triggers->item(0)->getAttribute('aria-label'))->toBe('Appearance')
        ->and($triggers->item(0)->hasAttribute('data-top-bar-action'))->toBeTrue()
        ->and($options->map->getAttribute('data-appearance-option')->all())->toBe(['light', 'dark', 'system'])
        ->and($options->map->getAttribute('value')->all())->toBe(['light', 'dark', 'system'])
        ->and($options->map(fn (DOMElement $option): string => trim($option->textContent))->all())->toBe(['Light', 'Dark', 'System']);
});

test('the appearance menu is translated in arabic', function () {
    $this->actingAs(User::factory()->role('Team Member')->create(['locale' => 'ar']));

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('aria-label="المظهر"', false)
        ->assertSeeInOrder([
            'data-appearance-option="light"', 'فاتح',
            'data-appearance-option="dark"', 'داكن',
            'data-appearance-option="system"', 'النظام',
        ], false);
});
