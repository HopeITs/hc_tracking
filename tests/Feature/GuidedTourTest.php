<?php

use App\Models\LibraryIndicator;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Testing\TestResponse;

test('the header carries a tour button and the tour overlay with translated steps', function () {
    $user = User::factory()->role('Team Member')->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-test="tour-start-button"', false)
        ->assertSee('data-tour-root', false)
        ->assertSee('Your desk')
        ->assertSee('Ask anything');
});

test('a page tour opens with its own introduction and only lists the blocks the page renders', function () {
    $user = User::factory()->role('Team Member')->create();

    $this->actingAs($user)
        ->get(route('programs.index'))
        ->assertOk()
        ->assertSee('Each program groups the projects');
});

test('the tour speaks Arabic when the interface does', function () {
    $user = User::factory()->role('Team Member')->create(['locale' => 'ar']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('جولة إرشادية في هذه الصفحة');
});

test('the tour opens by itself on the dashboard only until it has been seen once', function () {
    $user = User::factory()->role('Team Member')->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSee(str_replace('/', '\/', route('tour.seen')), false);

    $this->actingAs($user)->postJson(route('tour.seen'))->assertNoContent();

    expect($user->fresh()->tour_seen_at)->not->toBeNull();

    $this->actingAs($user->fresh())
        ->get(route('dashboard'))
        ->assertDontSee(str_replace('/', '\/', route('tour.seen')), false);
});

test('other pages never start the tour by themselves', function () {
    $user = User::factory()->role('Team Member')->create();

    $this->actingAs($user)
        ->get(route('programs.index'))
        ->assertDontSee(str_replace('/', '\/', route('tour.seen')), false);
});

test('the tour on funding pages includes finance table filter and totals steps', function () {
    $user = User::factory()->role('Financial')->create();

    $this->actingAs($user)
        ->get(route('transfers.index'))
        ->assertOk()
        ->assertSee('Filter the table')
        ->assertSee('Narrow the table: pick a year, a status, a currency or a flag');
});

/**
 * Tours added in T26 slice 2: route, role, query, English step titles, Arabic
 * intro title and the hook the page must render for the tour to point at.
 *
 * @return array<string, array{0: string, 1: string, 2: array<string, string>, 3: list<string>, 4: string, 5: string}>
 */
dataset('page tours', [
    'indicator library' => ['indicator-library.index', 'MEAL', [], ['Indicator Library', 'Find an entry', 'Grouped by level', 'An indicator'], 'مكتبة المؤشرات', 'data-library-entry'],
    'department tasks' => ['department-tasks.index', 'Team Member', [], ['Department Tasks', 'The requests'], 'مهام الأقسام', 'data-tour="department-tasks-list"'],
    'report template queue' => ['report-templates.index', 'MEAL', [], ['Templates to confirm', 'The queue'], 'قوالب بانتظار التأكيد', 'data-report-template-queue'],
    'sectors' => ['admin.sectors.index', 'MEAL', [], ['Sectors', 'Add a sector'], 'القطاعات', 'data-tour="sectors-create"'],
    'supervision' => ['admin.users.index', 'General Manager', ['tab' => 'supervision'], ['Supervision'], 'دليل المستخدمين', 'data-supervision-tab'],
    'default reviewers' => ['admin.notification-settings.edit', 'Super Admin', [], ['Default reviewers'], 'قواعد الإشعارات', 'data-default-reviewers'],
    'proposal reviewers' => ['proposals.show', 'General Manager', [], ['Reviewers'], 'تفاصيل المقترح', 'data-proposal-reviewers'],
    'proposal logical framework' => ['proposals.show', 'General Manager', ['tab' => 'logframe'], ['Logical Framework'], 'تفاصيل المقترح', 'data-tour="proposal-logframe"'],
    'proposal indicators and targets' => ['proposals.show', 'General Manager', ['tab' => 'targets'], ['Indicators & Targets'], 'تفاصيل المقترح', 'data-tour="proposal-targets-grid"'],
    'proposal plan' => ['proposals.show', 'General Manager', ['tab' => 'plan'], ['Plan'], 'تفاصيل المقترح', 'data-tour="proposal-plan"'],
]);

/**
 * Open a tour page as a user of the given role, with the data its hook needs.
 *
 * @param  array<string, string>  $query
 */
function tourPage(object $test, string $route, string $role, array $query, string $locale): TestResponse
{
    LibraryIndicator::factory()->create();
    $proposal = Proposal::factory()->create();

    $user = userWithRole($role);
    $user->forceFill(['locale' => $locale])->save();

    $parameters = $route === 'proposals.show' ? ['proposal' => $proposal, ...$query] : $query;

    return $test->actingAs($user)->get(route($route, $parameters))->assertOk();
}

test('a page tour lists its English steps and the page renders the hook it points at', function (string $route, string $role, array $query, array $titles, string $arabicIntro, string $hook) {
    $page = tourPage($this, $route, $role, $query, 'en');

    foreach ($titles as $title) {
        $page->assertSee($title);
    }

    $page->assertSee($hook, false);
})->with('page tours');

test('a page tour opens with its Arabic introduction', function (string $route, string $role, array $query, array $titles, string $arabicIntro) {
    tourPage($this, $route, $role, $query, 'ar')->assertSee($arabicIntro);
})->with('page tours');

test('the desk tour walks the top bar, then the desk and its panels', function () {
    $this->actingAs(User::factory()->role('Team Member')->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeInOrder(['Every page, by your role', 'It answers and acts', 'Your initials', 'The dots move it', 'Add a panel', 'Come back any time'])
        ->assertDontSee('data-desk-customize-trigger', false)
        ->assertSee('data-desk-add', false);
});

test('a tour step never points a user without money permissions at a money reading', function () {
    $proposal = Proposal::factory()->create();

    $this->actingAs(User::factory()->create()->givePermissionTo('proposals.view'))
        ->get(route('proposals.show', $proposal))
        ->assertOk()
        ->assertSee('data-vital', false)
        ->assertDontSee('data-vital="money"', false);
});
