<?php

use App\Models\DonorReport;
use App\Models\User;
use App\Support\Desk\Destinations;
use Illuminate\Support\Facades\Route;

/**
 * The pages a user can open from the Ask bar's "Go to" list, in order.
 *
 * @return list<string>
 */
function deskPageRoutes(User $user): array
{
    return array_column(app(Destinations::class)->for($user, Destinations::Pages), 'route');
}

/**
 * The Settings sheet rows a user can open (unlocked), in order.
 *
 * @return list<string>
 */
function openSettingsRoutes(User $user): array
{
    return array_column(array_filter(app(Destinations::class)->settingsFor($user), fn (array $row): bool => $row['open']), 'route');
}

test('the desk replaces the sidebar: no sidebar or old header renders, the top bar does', function () {
    $this->actingAs(User::factory()->role('Team Member')->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-desk-top-bar', false)
        ->assertSee('data-desk-ask', false)
        ->assertDontSee('data-navigation-sidebar', false)
        ->assertDontSee('data-desktop-navigation-header', false)
        ->assertDontSee('data-mobile-navigation-toggle', false);
});

test('a Super Admin can go to every page, in the order the work flows', function () {
    expect(deskPageRoutes(User::factory()->role('Super Admin')->create()))->toBe([
        'dashboard',
        'strategies.index', 'programs.index', 'annual-plans.index',
        'projects.index', 'milestones.index', 'timeline.index', 'risks.index',
        'results.index', 'indicators.index', 'targets.index', 'reports.index', 'reports.monthly-matrix', 'monthly-reports.index',
        'donor-reports.compose', 'reporting-duties.index',
        'donors.index', 'proposals.index', 'funding-agreements.index', 'transfers.index',
        'tasks.index', 'department-tasks.index', 'calendar.index', 'notifications.index',
    ]);
});

test('a Team Member opens only the indicator library, the read-only cities, sectors and strategies and their own settings', function () {
    expect(openSettingsRoutes(User::factory()->role('Team Member')->create()))
        ->toBe(['security.edit', 'notification-settings.edit', 'indicator-library.index', 'admin.sectors.index', 'admin.cities.index', 'strategies.index']);
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
        ->and(openSettingsRoutes(User::factory()->role('Financial')->create()))->toContain('admin.accounts.index')
        ->and(deskPageRoutes(User::factory()->role('Public Relations Manager')->create()))->toContain('donors.index', 'proposals.index', 'funding-agreements.index')->not->toContain('transfers.index')
        ->and(array_intersect(deskPageRoutes(User::factory()->role('Team Member')->create()), $funding))->toBe([]);
});

test('every page and settings destination is a registered route', function () {
    foreach ([...deskPageRoutes(User::factory()->role('Super Admin')->create()), ...array_column(app(Destinations::class)->settingsFor(User::factory()->role('Super Admin')->create()), 'route')] as $route) {
        expect(Route::has($route))->toBeTrue($route);
    }
});

test('the top bar links to the user guideline and the settings sheet to the bug report form, both in a new tab', function () {
    $html = $this->actingAs(User::factory()->role('Team Member')->create())->get(route('dashboard'))->assertOk()->getContent();
    $xpath = new DOMXPath(tap(new DOMDocument, fn (DOMDocument $document) => $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING)));

    $guideline = $xpath->query('//*[@data-desk-top-bar]//a[@data-guideline]');
    $report = $xpath->query('//*[@data-report-bug]//a');

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
