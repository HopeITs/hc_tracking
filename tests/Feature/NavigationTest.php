<?php

use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The route names the sidebar links to, in the order it lists them.
 *
 * @return list<string>
 */
function sidebarRouteNames(string $html): array
{
    $document = new DOMDocument;
    $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $links = (new DOMXPath($document))->query('//*[@data-navigation-sidebar]//nav//a');

    return collect($links)
        ->map(fn (DOMElement $link): string => app('router')->getRoutes()->match(Request::create($link->getAttribute('href')))->getName())
        ->all();
}

test('the sidebar groups navigation in the application workflow order', function () {
    $user = User::factory()->role('Team Member')->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeTextInOrder(['Overview', 'Strategy & Planning', 'Projects & Delivery', 'Monitoring & Evaluation', 'My Workspace', 'Administration']);
});

test('the sidebar lists every page for a Super Admin with funding in the order money arrives, daily work after it and the reference lists last', function () {
    $user = User::factory()->role('Super Admin')->create();

    $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

    expect(sidebarRouteNames($html))->toBe([
        'dashboard', 'executive-summary.index',
        'strategies.index', 'programs.index', 'annual-plans.index',
        'projects.index', 'milestones.index', 'timeline.index', 'risks.index',
        'results.index', 'indicator-library.index', 'indicators.index', 'targets.index', 'reports.index',
        'donors.index', 'proposals.index', 'funding-agreements.index', 'transfers.index', 'admin.accounts.index',
        'tasks.index', 'department-tasks.index', 'calendar.index',
        'admin.users.index', 'admin.notification-settings.edit', 'activity.index', 'admin.cities.index', 'admin.sectors.index',
        'admin.evidence-types.index', 'admin.narrative-questions.index',
    ]);
});

test('the sidebar leaves a Team Member only the read-only cities and sectors under administration', function () {
    $user = User::factory()->role('Team Member')->create();

    $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

    expect(array_slice(sidebarRouteNames($html), -5))->toBe(['tasks.index', 'department-tasks.index', 'calendar.index', 'admin.cities.index', 'admin.sectors.index']);
});

test('the sidebar drops the administration heading for someone who holds none of its pages', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSeeText('Administration');
});

test('the sidebar carries a funding group for Financial, who holds every funding permission', function () {
    $user = User::factory()->role('Financial')->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeText('Funding & Finance')
        ->assertSeeText('Proposals')
        ->assertSeeText('Donors')
        ->assertSeeText('Funding Agreements')
        ->assertSeeText('Transfers')
        ->assertSeeText('Accounts');
});

test('the sidebar shows Public Relations Manager proposals, donors and agreements but not transfers', function () {
    $user = User::factory()->role('Public Relations Manager')->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeText('Funding & Finance')
        ->assertSeeText('Proposals')
        ->assertSeeText('Donors')
        ->assertSeeText('Funding Agreements')
        ->assertDontSeeText('Transfers');
});

test('the sidebar hides the funding group from a Team Member', function () {
    $user = User::factory()->role('Team Member')->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSeeText('Funding')
        ->assertDontSeeText('Donors')
        ->assertDontSeeText('Funding Agreements')
        ->assertDontSeeText('Transfers')
        ->assertDontSee('href="'.route('proposals.index').'"', false)
        ->assertDontSee('href="'.route('funding-agreements.index').'"', false);
});

test('the sidebar carries a monitoring and evaluation group once its routes exist', function () {
    $user = User::factory()->role('Team Member')->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeText('Monitoring & Evaluation')
        ->assertSeeText('Results Framework')
        ->assertSeeText('Indicator Library')
        ->assertSeeText('Indicators')
        ->assertSeeText('Targets')
        ->assertSeeText('Reports');
});

test('every sidebar link resolves to a registered route', function () {
    $user = User::factory()->role('Team Member')->create();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
});

test('the sidebar carries route-backed delivery links', function () {
    $user = User::factory()->role('Team Member')->create();

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
    $user = User::factory()->role('Team Member')->create();
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
    $user = User::factory()->role('Team Member')->create();

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
    $user = User::factory()->role('Team Member')->create();

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
    $user = User::factory()->role('Team Member')->create();

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

test('both top headers link to the bug report form in a new tab', function () {
    $user = User::factory()->role('Team Member')->create();

    $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $document = new DOMDocument;
    $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
    $links = (new DOMXPath($document))->query('//a[@data-report-bug]');

    expect($links)->toHaveCount(2);

    foreach ($links as $link) {
        expect($link->getAttribute('href'))->toBe('https://forms.gle/5QfGGarkeejAe3HS6')
            ->and($link->getAttribute('target'))->toBe('_blank')
            ->and($link->getAttribute('rel'))->toContain('noopener')
            ->and($link->getAttribute('aria-label'))->toBe('Report a problem or bug')
            ->and($link->hasAttribute('data-top-bar-action'))->toBeTrue();
    }
});

test('both top headers link to the user guideline in a new tab', function () {
    $user = User::factory()->role('Team Member')->create();

    $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $document = new DOMDocument;
    $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
    $links = (new DOMXPath($document))->query('//a[@data-guideline]');

    expect($links)->toHaveCount(2);

    foreach ($links as $link) {
        expect($link->getAttribute('href'))->toBe('https://docs.google.com/document/d/1-cQQQrmqRirFbkwU22RL9YkpIY1_D8n8/edit')
            ->and($link->getAttribute('target'))->toBe('_blank')
            ->and($link->getAttribute('rel'))->toContain('noopener')
            ->and($link->getAttribute('aria-label'))->toBe('User guideline')
            ->and($link->hasAttribute('data-top-bar-action'))->toBeTrue();
    }
});

test('both top headers gather their icon actions on one track, apart from the language toggle', function () {
    $user = User::factory()->role('Team Member')->create();

    $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $document = new DOMDocument;
    $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);

    $groups = $xpath->query('//*[@data-top-bar-actions]//*[@data-top-bar-action-group]');
    $desktopGroup = $xpath->query('//*[@data-desktop-navigation-header]//*[@data-top-bar-action-group]');

    expect($groups)->toHaveCount(2)
        ->and($desktopGroup)->toHaveCount(1)
        ->and($xpath->query('.//*[@data-theme-toggle]', $desktopGroup->item(0)))->toHaveCount(1)
        ->and($xpath->query('.//*[@data-locale-toggle]', $desktopGroup->item(0)))->toHaveCount(0);

    foreach ($groups as $group) {
        expect($xpath->query('.//*[@data-test="notification-bell-button"]', $group))->toHaveCount(1)
            ->and($xpath->query('.//*[@data-report-bug]', $group))->toHaveCount(1)
            ->and($xpath->query('.//*[@data-guideline]', $group))->toHaveCount(1)
            ->and($xpath->query('.//*[@data-test="tour-start-button"]', $group))->toHaveCount(1);
    }
});

test('the sidebar arms its collapse animation only from a toggle, so a restored state renders without motion', function () {
    $user = User::factory()->role('Team Member')->create();

    $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $document = new DOMDocument;
    $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
    $sidebar = (new DOMXPath($document))->query('//*[@data-navigation-sidebar]')->item(0);

    // app.css only transitions the rail while data-sidebar-animating is set.
    expect($sidebar->hasAttribute('data-sidebar-animating'))->toBeFalse()
        ->and($sidebar->getAttribute('x-on:flux-sidebar-toggle.document'))->toContain("setAttribute('data-sidebar-animating'", "removeAttribute('data-sidebar-animating')");
});

test('the sidebar brand link keeps a single accessible name while the wordmark and mark crossfade', function () {
    $user = User::factory()->role('Team Member')->create();

    $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $document = new DOMDocument;
    $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);

    $wordmarkAlts = collect($xpath->query('//*[@data-navigation-sidebar]//*[@data-sidebar-logo-wordmark]//img'))->map->getAttribute('alt')->all();
    $markAlts = collect($xpath->query('//*[@data-navigation-sidebar]//*[@data-sidebar-logo-mark]//img'))->map->getAttribute('alt')->all();

    // Both stay in the DOM on desktop; only the wordmark (light and dark) names the link.
    expect($wordmarkAlts)->toBe([config('app.name'), config('app.name')])
        ->and($markAlts)->toBe(['']);
});

test('the mobile drawer puts its theme button and language toggle in their own row so the logo has room', function () {
    $user = User::factory()->role('Team Member')->create();

    $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $document = new DOMDocument;
    $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);

    $headerPreferences = $xpath->query('//*[@data-navigation-sidebar]//*[@data-flux-sidebar-header]//*[@data-sidebar-preferences]');
    $drawerPreferences = $xpath->query('//*[@data-navigation-sidebar]/*[@data-sidebar-preferences]');

    $themeToggles = $xpath->query('.//*[@data-theme-toggle]', $drawerPreferences->item(0));

    expect($headerPreferences)->toHaveCount(0)
        ->and($drawerPreferences)->toHaveCount(1)
        ->and($themeToggles)->toHaveCount(1)
        ->and($themeToggles->item(0)->getAttribute('x-on:click'))->toBe('hcToggleAppearance()')
        ->and($xpath->query('ancestor::ui-dropdown', $themeToggles->item(0)))->toHaveCount(0)
        ->and($xpath->query('.//*[@data-locale-toggle]', $drawerPreferences->item(0)))->toHaveCount(1);
});
