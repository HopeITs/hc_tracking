<?php

use App\Models\Account;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\EvidenceType;
use App\Models\Indicator;
use App\Models\LibraryIndicator;
use App\Models\NarrativeQuestion;
use App\Models\Report;
use App\Models\Result;
use App\Models\RiskLibrary;
use App\Models\Sector;
use App\Models\Strategy;
use App\Models\User;
use App\Support\Desk\PageCards;
use Livewire\Livewire;

/**
 * Every page with a card of its own (PageCards), kept in step by the test below it.
 *
 * @var list<string>
 */
const DeskPageCardRoutes = [
    'strategies.index', 'programs.index', 'annual-plans.index', 'results.index', 'indicator-library.index', 'indicators.index',
    'reports.index', 'donor-reports.index', 'funding-agreements.index', 'admin.accounts.index', 'admin.users.index', 'activity.index',
    'admin.cities.index', 'admin.sectors.index', 'admin.risk-library.index', 'admin.evidence-types.index', 'admin.narrative-questions.index',
];

beforeEach(function () {
    $this->travelTo('2026-09-15 09:00:00');
});

test('the card list here is the one PageCards holds', function () {
    expect(app(PageCards::class)->routes())->toBe(DeskPageCardRoutes);
});

test('every page card renders for a super admin, with its figures and rows', function (string $route) {
    Strategy::factory()->create();
    Sector::factory()->create();
    EvidenceType::factory()->create();
    NarrativeQuestion::factory()->create();
    RiskLibrary::factory()->create();
    Account::factory()->create();
    LibraryIndicator::factory()->create();
    Result::factory()->create();
    Indicator::factory()->create();
    Report::factory()->create();
    DonorReport::factory()->create();
    DonorProposal::factory()->create();

    /* The risk library is its MEAL Manager slot holder's alone, a Super Admin's too (D-61). */
    $viewer = $route === 'admin.risk-library.index' ? mealManager() : User::factory()->role('Super Admin')->create();

    Livewire::actingAs($viewer)
        ->test('desk.panels.page', ['card' => PageCards::idFor($route), 'size' => 'L', 'gridCols' => 8, 'gridRows' => 5])
        ->assertOk()
        ->assertSeeHtml('data-card="'.$route.'"')
        ->assertSeeHtml('data-card-figures');
})->with(DeskPageCardRoutes);

test('each card draws its records in the shape of what they are', function () {
    Strategy::factory()->create(['start_year' => 2026, 'end_year' => 2030]);
    EvidenceType::factory()->create(['name_en' => 'Attendance sheet']);
    $admin = User::factory()->role('Super Admin')->create();
    $render = fn (string $route) => Livewire::actingAs($admin)
        ->test('desk.panels.page', ['card' => PageCards::idFor($route), 'size' => 'L', 'gridCols' => 6, 'gridRows' => 5]);

    $layouts = collect(DeskPageCardRoutes)->reject(fn (string $route): bool => $route === 'admin.risk-library.index')
        ->map(fn (string $route): string => app(PageCards::class)->for($route)->layout());

    expect($layouts->unique()->count())->toBeGreaterThanOrEqual(10);

    $render('strategies.index')
        ->assertSeeHtml('data-layout="timeline"')
        ->assertSeeHtml('class="dk-card-r" data-tone="good"')
        ->assertSeeHtml('--now: ');

    // A record nobody uses yet shows an empty track, not a sliver that reads as "a little".
    $render('admin.evidence-types.index')
        ->assertSeeHtml('data-layout="meter"')
        ->assertSeeHtml('style="--from: 0%; --to: 0%;"')
        ->assertDontSeeHtml('<i></i>');
});

test('every card has a picture of its own, and no two cards share one', function () {
    $admin = User::factory()->role('Super Admin')->create();
    Strategy::factory()->create(['start_year' => 2026, 'end_year' => 2030]);

    $kinds = collect(DeskPageCardRoutes)
        ->map(fn (string $route): ?string => app(PageCards::class)->for($route)->hero($admin)['kind'] ?? null);

    expect($kinds->filter()->count())->toBe(count(DeskPageCardRoutes))
        ->and($kinds->unique()->count())->toBe(count(DeskPageCardRoutes));

    Livewire::actingAs($admin)
        ->test('desk.panels.page', ['card' => PageCards::idFor('strategies.index'), 'size' => 'L', 'gridCols' => 6, 'gridRows' => 5])
        ->assertSeeHtml('data-hero="ruler"')
        ->assertSeeHtml('2026–2030')
        // The figures stay for screen readers when the picture shows.
        ->assertSeeHtml('class="dk-card-figs sr-only" data-card-figures');
});

test('a card lists its page\'s records, opening them, and its search narrows them in either language', function () {
    EvidenceType::factory()->create(['name_en' => 'Attendance sheet', 'name_ar' => 'كشف الحضور']);
    EvidenceType::factory()->create(['name_en' => 'Distribution photo', 'name_ar' => 'صورة التوزيع']);
    $meal = User::factory()->role('MEAL')->create();

    Livewire::actingAs($meal)
        ->test('desk.panels.page', ['card' => 'p-admin-evidence-types', 'size' => 'L', 'gridCols' => 6, 'gridRows' => 5])
        ->assertSee('Attendance sheet')
        ->assertSee('Distribution photo')
        ->assertSeeHtml('href="'.route('admin.evidence-types.index').'"')
        ->assertSeeHtml('data-card-create')
        // The search narrows the rows; the card's picture above them still counts every type.
        ->set('search', 'attendance')
        ->assertSeeHtml('class="dk-card-row" title="Attendance sheet"')
        ->assertDontSeeHtml('class="dk-card-row" title="Distribution photo"')
        ->set('search', 'التوزيع')
        ->assertSeeHtml('class="dk-card-row" title="Distribution photo"')
        ->assertDontSeeHtml('class="dk-card-row" title="Attendance sheet"');
});

test('a card shows nothing of a page its viewer may not open', function () {
    Account::factory()->create(['account_name' => 'Operations reserve']);

    Livewire::actingAs(User::factory()->role('Team Member')->create())
        ->test('desk.panels.page', ['card' => 'p-admin-accounts', 'size' => 'L'])
        ->assertOk()
        ->assertDontSee('Operations reserve')
        ->assertSee(__('desk-workspace.card.unavailable'));
});

test('a card offers its page\'s "New …" only to someone who may create there', function () {
    $card = fn (User $user) => Livewire::actingAs($user)->test('desk.panels.page', ['card' => 'p-strategies', 'size' => 'L']);

    $card(User::factory()->role('Super Admin')->create())->assertSeeHtml('data-card-create');
    $card(User::factory()->role('Team Member')->create())->assertDontSeeHtml('data-card-create');
});

test('a donor report card shows a responsible person only the reports they answer for', function () {
    $mine = DonorReport::factory()->create(['title' => 'My quarterly report']);
    DonorReport::factory()->create(['title' => 'Someone else\'s report']);
    $employee = insertHrEmployee();
    $mine->forceFill(['responsible_employee_id' => $employee])->save();

    $user = User::factory()->role('Team Member')->create(['employee_id' => $employee]);
    $rows = app(PageCards::class)->for('donor-reports.index')->rows($user)->pluck('title')->all();

    expect($rows)->toBe(['My quarterly report']);
});
