<?php

use App\Models\Project;
use App\Models\User;

test('the sidebar groups navigation in the application workflow order', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeTextInOrder(['Overview', 'Strategy', 'Delivery', 'Administration']);
});

test('the sidebar carries a funding group once its routes exist', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeText('Funding')
        ->assertSeeText('Proposals')
        ->assertSeeText('Donors')
        ->assertSeeText('Funding Agreements')
        ->assertSeeText('Transfers')
        ->assertSeeText('Accounts');
});

test('the sidebar carries a results and learning group once its routes exist', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeText('Results & Learning')
        ->assertSeeText('Results Framework')
        ->assertSeeText('Indicators')
        ->assertSeeText('Targets')
        ->assertSeeText('Reports');
});

test('every sidebar link resolves to a registered route', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
});

test('the sidebar carries route-backed delivery links', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $document = new DOMDocument;
    $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);

    foreach ([
        'projects.index' => 'Projects',
        'milestones.index' => 'Milestones',
        'tasks.index' => 'Tasks',
        'risks.index' => 'Risks',
    ] as $routeName => $label) {
        $links = $xpath->query(sprintf(
            '//a[@href="%s" and starts-with(normalize-space(.), "%s")]',
            route($routeName),
            $label,
        ));

        expect($links)->toHaveCount(1);
    }
});

test('a project detail page highlights the projects item', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();

    $response = $this->actingAs($user)->get(route('projects.show', $project))->assertOk();

    $document = new DOMDocument;
    $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);
    $currentProjectsLinks = $xpath->query(sprintf(
        '//a[@href="%s" and starts-with(normalize-space(.), "Projects") and @data-current]',
        route('projects.index'),
    ));

    expect($currentProjectsLinks)->toHaveCount(1);
});

test('the sidebar collapse toggle carries a translated accessible name, not an Alpine binding', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $document = new DOMDocument;
    $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);

    $toggles = $xpath->query('//*[@data-desktop-navigation-collapse]');

    expect($toggles)->toHaveCount(1);

    $toggle = $toggles->item(0);

    // The div carries x-data, so a Blade ":attr" here would be claimed by Alpine
    // and evaluated as JavaScript: __() is not defined in the browser, which
    // throws on every page load and leaves the control without a name.
    expect($toggle->getAttribute('aria-label'))->toBe(__('navigation.toggle_sidebar'))
        ->and($toggle->hasAttribute(':aria-label'))->toBeFalse();
});

test('the sidebar stacks above the desktop top header so its edge toggle is not covered', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $document = new DOMDocument;
    $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);

    $sidebarClasses = explode(' ', $xpath->query('//*[@data-navigation-sidebar]')->item(0)->getAttribute('class'));
    $headerClasses = explode(' ', $xpath->query('//*[@data-desktop-navigation-header]')->item(0)->getAttribute('class'));

    // The toggle straddles the sidebar edge into the header row; the header is z-40.
    expect($sidebarClasses)->toContain('z-50!')
        ->and($headerClasses)->toContain('z-40');
});

test('the sidebar header matches the desktop top header height so their bottom borders form one line', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $document = new DOMDocument;
    $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);

    $sidebarHeaderClasses = explode(' ', $xpath->query('//*[@data-navigation-sidebar]//*[@data-flux-sidebar-header]')->item(0)->getAttribute('class'));
    $headerClasses = explode(' ', $xpath->query('//*[@data-desktop-navigation-header]')->item(0)->getAttribute('class'));

    // The negative margins cancel the sidebar's p-3 so the border reaches both edges.
    expect($sidebarHeaderClasses)->toContain('h-16', '-mx-3', '-mt-3', 'border-b')
        ->and($headerClasses)->toContain('min-h-16', 'border-b');
});
