<?php

use App\Models\DonorReport;
use App\Models\User;
use App\Support\Desk\Destinations;
use Illuminate\Support\Facades\Route;

/**
 * The pages a user's sidebar lists, in order.
 *
 * @return list<string>
 */
function deskPageRoutes(User $user): array
{
    return array_column(app(Destinations::class)->for($user), 'route');
}

test('every page renders the sidebar and the top bar with its tools', function () {
    $this->actingAs(User::factory()->role('Team Member')->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-side-nav', false)
        ->assertSee('data-desk-top-bar', false)
        ->assertSee('data-desk-ask', false)
        ->assertSee('data-panels-trigger', false)
        ->assertSee('data-notification-bell', false)
        ->assertSee('data-user-menu', false);
});

test('the profile menu leaves notification preferences and rules to the bell', function () {
    $html = $this->actingAs(User::factory()->role('Super Admin')->create())->get(route('dashboard'))->assertOk()->getContent();
    $menu = str($html)->after('dk-usermenu-pop')->before('dk-usermenu-out')->toString();

    expect($menu)->toContain(route('security.edit'))
        ->not->toContain(route('notification-settings.edit'))
        ->not->toContain(route('admin.notification-settings.edit'));
});

test('a Super Admin sidebar lists every page, grouped like the old sidebar, the Desk first', function () {
    $groups = app(Destinations::class)->sidebarFor(User::factory()->role('Super Admin')->create());

    expect(array_column($groups, 'group'))->toBe(['overview', 'strategy_planning', 'projects_delivery', 'monitoring_evaluation', 'funding_finance', 'workspace', 'administration', 'you'])
        ->and($groups[0]['items'][0]['route'])->toBe('dashboard')
        ->and(array_column($groups[6]['items'], 'route'))->toContain('admin.users.index', 'activity.index', 'admin.cities.index')
        ->and(array_column($groups[3]['items'], 'route'))->toContain('report-templates.index');
});

test('a Team Member sidebar leaves out the pages their role cannot open', function () {
    $routes = deskPageRoutes(User::factory()->role('Team Member')->create());

    expect($routes)->toContain('dashboard', 'security.edit', 'notification-settings.edit')
        ->not->toContain('admin.users.index', 'admin.accounts.index', 'admin.notification-settings.edit', 'transfers.index');
});

test('the rendered sidebar links only what the role may open', function () {
    $this->actingAs(User::factory()->role('Team Member')->create())
        ->get(route('dashboard'))
        ->assertSee('data-side-link="dashboard"', false)
        ->assertDontSee('data-side-link="admin.users.index"', false)
        ->assertDontSee('data-side-link="admin.accounts.index"', false);
});

test('the donor report builder goes only to reports.aggregate holders, reporting duties to responsibles and agreement editors', function () {
    $employeeId = insertHrEmployee();
    $responsible = User::factory()->role('Team Member')->create(['employee_id' => $employeeId]);
    DonorReport::factory()->create(['responsible_employee_id' => $employeeId]);

    expect(deskPageRoutes(User::factory()->role('Team Member')->create()))->not->toContain('donor-reports.compose')->not->toContain('reporting-duties.index')
        ->and(deskPageRoutes($responsible))->toContain('reporting-duties.index')->not->toContain('donor-reports.compose')
        ->and(deskPageRoutes(User::factory()->role('Financial')->create()))->toContain('reporting-duties.index')->not->toContain('donor-reports.compose')
        ->and(deskPageRoutes(User::factory()->role('MEAL')->create()))->toContain('donor-reports.compose');
});

test('funding pages follow the module permissions of each role', function () {
    $funding = ['donors.index', 'proposals.index', 'funding-agreements.index', 'transfers.index'];

    expect(deskPageRoutes(User::factory()->role('Financial')->create()))->toContain(...$funding)
        ->and(deskPageRoutes(User::factory()->role('Financial')->create()))->toContain('admin.accounts.index')
        ->and(deskPageRoutes(User::factory()->role('Public Relations Manager')->create()))->toContain('donors.index', 'proposals.index', 'funding-agreements.index')->not->toContain('transfers.index')
        ->and(array_intersect(deskPageRoutes(User::factory()->role('Team Member')->create()), $funding))->toBe([]);
});

test('every destination is a registered route', function () {
    foreach (deskPageRoutes(User::factory()->role('Super Admin')->create()) as $route) {
        expect(Route::has($route))->toBeTrue($route);
    }
});

test('the top bar links to the user guideline and the bug report form, both in a new tab', function () {
    $html = $this->actingAs(User::factory()->role('Team Member')->create())->get(route('dashboard'))->assertOk()->getContent();
    $xpath = new DOMXPath(tap(new DOMDocument, fn (DOMDocument $document) => $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING)));

    $guideline = $xpath->query('//*[@data-desk-top-bar]//a[@data-guideline]');
    $report = $xpath->query('//a[@data-report-bug]');

    expect($guideline)->toHaveCount(1)
        ->and($guideline->item(0)->getAttribute('target'))->toBe('_blank')
        ->and($report)->toHaveCount(1)
        ->and($report->item(0)->getAttribute('href'))->toBe('https://forms.gle/5QfGGarkeejAe3HS6')
        ->and($report->item(0)->getAttribute('target'))->toBe('_blank')
        ->and($report->item(0)->getAttribute('rel'))->toContain('noopener');
});

test('the logo link keeps a single accessible name while the light and dark logos swap', function () {
    $html = $this->actingAs(User::factory()->role('Team Member')->create())->get(route('dashboard'))->assertOk()->getContent();
    $xpath = new DOMXPath(tap(new DOMDocument, fn (DOMDocument $document) => $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING)));
    $logo = $xpath->query('//a[@data-desk-logo]');

    expect($logo)->toHaveCount(1)
        ->and($logo->item(0)->getAttribute('aria-label'))->toBe(__('desk.logo_label'))
        ->and($logo->item(0)->getAttribute('href'))->toBe(route('dashboard'));
});
