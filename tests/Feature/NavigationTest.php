<?php

use App\Models\Project;
use App\Models\User;

test('the sidebar groups navigation in the application workflow order', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeTextInOrder(['Overview', 'Strategy', 'Delivery', 'Administration', 'Settings']);
});

test('the sidebar does not render a group for routes that do not exist yet', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSeeText('Results & Learning')
        ->assertDontSeeText('Funding');
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
            '//a[@href="%s" and normalize-space(.)="%s"]',
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
        '//a[@href="%s" and normalize-space(.)="Projects" and @data-current]',
        route('projects.index'),
    ));

    expect($currentProjectsLinks)->toHaveCount(1);
});
