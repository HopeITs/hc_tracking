<?php

use App\Models\Program;
use App\Models\Project;
use App\Models\Strategy;
use App\Models\User;
use App\Models\YearlyPlan;

test('guests are redirected from strategy detail', function () {
    $strategy = Strategy::factory()->create();

    $this->get(route('strategies.show', $strategy))->assertRedirect(route('login'));
});

test('strategy detail shows the strategy its period and its programs', function () {
    $user = User::factory()->create();
    $manager = insertHrEmployee(firstNameEn: 'Layla', lastNameEn: 'Haddad');
    $strategy = Strategy::factory()->create([
        'name' => 'Country Strategy',
        'start_year' => 2026,
        'end_year' => 2030,
    ]);
    Program::factory()->create([
        'name' => 'Water Security',
        'strategy_id' => $strategy->id,
        'manager_id' => $manager,
    ]);

    $this->actingAs($user)
        ->get(route('strategies.show', $strategy))
        ->assertOk()
        ->assertSeeText('Country Strategy')
        ->assertSeeText('Strategic period 2026 to 2030')
        ->assertSeeText('Water Security')
        ->assertSeeText('Layla Haddad');
});

test('strategy detail does not show another strategy programs', function () {
    $user = User::factory()->create();
    $subject = Strategy::factory()->create(['name' => 'Country Strategy']);
    $other = Strategy::factory()->create(['name' => 'Regional Strategy']);

    Program::factory()->create(['name' => 'Water Security', 'strategy_id' => $subject->id]);
    Program::factory()->create(['name' => 'Safe Shelter', 'strategy_id' => $other->id]);

    $this->actingAs($user)
        ->get(route('strategies.show', $subject))
        ->assertOk()
        ->assertSeeText('Water Security')
        ->assertDontSeeText('Safe Shelter');
});

test('strategy detail renders a strategy with no programs', function () {
    $user = User::factory()->create();
    $strategy = Strategy::factory()->create(['name' => 'Empty Strategy']);

    $this->actingAs($user)
        ->get(route('strategies.show', $strategy))
        ->assertOk()
        ->assertSeeText('Empty Strategy')
        ->assertSeeText('No programs yet');
});

test('plan coverage is the share of the strategy span with an annual plan, capped at 100', function () {
    $user = User::factory()->create();
    $strategy = Strategy::factory()->create(['start_year' => 2026, 'end_year' => 2029]);
    $program = Program::factory()->create(['name' => 'Water Security', 'strategy_id' => $strategy->id]);

    // 2 of the 4 span years covered (2027 is outside the span and must not count).
    YearlyPlan::factory()->create(['program_id' => $program->id, 'year' => 2026]);
    YearlyPlan::factory()->create(['program_id' => $program->id, 'year' => 2028]);
    YearlyPlan::factory()->create(['program_id' => $program->id, 'year' => 2031]);

    $this->actingAs($user)
        ->get(route('strategies.show', $strategy))
        ->assertOk()
        ->assertSeeText('50%')
        ->assertDontSeeText('75%')
        ->assertDontSeeText('100%');
});

test('each program row tones its plan coverage bar by how covered it is', function () {
    $user = User::factory()->create();
    $strategy = Strategy::factory()->create(['start_year' => 2026, 'end_year' => 2027]);
    $uncovered = Program::factory()->create(['name' => 'Uncovered Program', 'strategy_id' => $strategy->id]);
    $fullyCovered = Program::factory()->create(['name' => 'Fully Covered Program', 'strategy_id' => $strategy->id]);

    YearlyPlan::factory()->create(['program_id' => $fullyCovered->id, 'year' => 2026]);
    YearlyPlan::factory()->create(['program_id' => $fullyCovered->id, 'year' => 2027]);

    $html = $this->actingAs($user)->get(route('strategies.show', $strategy))->getContent();

    expect($html)
        // 0% -> warn (amber), 100% -> good (green).
        ->toContain('bg-status-warn')
        ->toContain('bg-status-good');
});

test('the strategy detail stat tiles each carry an icon badge', function () {
    $user = User::factory()->create();
    $strategy = Strategy::factory()->create();

    $html = $this->actingAs($user)->get(route('strategies.show', $strategy))->getContent();

    // Programs, Projects, Annual plans and Plan coverage each render one icon
    // badge with this exact class combination.
    expect(substr_count($html, 'shrink-0 items-center justify-center rounded-xl'))->toBe(4);
});

test('the strategy detail programs table sorts by projects descending', function () {
    $user = User::factory()->create();
    $strategy = Strategy::factory()->create();
    $fewer = Program::factory()->create(['name' => 'Fewer Projects Program', 'strategy_id' => $strategy->id]);
    $more = Program::factory()->create(['name' => 'More Projects Program', 'strategy_id' => $strategy->id]);
    Project::factory()->count(3)->create(['program_id' => $more->id]);
    Project::factory()->count(1)->create(['program_id' => $fewer->id]);

    $this->actingAs($user)
        ->get(route('strategies.show', [$strategy, 'sort' => 'projects', 'direction' => 'desc']))
        ->assertOk()
        ->assertSeeTextInOrder(['More Projects Program', 'Fewer Projects Program']);
});

test('an active strategy is shown with the good/green status tone, not the brand red', function () {
    $user = User::factory()->create();
    $strategy = Strategy::factory()->create(['start_year' => now()->year, 'end_year' => now()->year + 2]);

    $this->actingAs($user)
        ->get(route('strategies.show', $strategy))
        ->assertOk()
        ->assertSee('bg-status-good text-white', false)
        ->assertDontSee('bg-primary text-white', false);
});

test('the strategy detail page has no timeline, only the aggregate plan coverage tile', function () {
    $user = User::factory()->create();
    $strategy = Strategy::factory()->create(['start_year' => 2026, 'end_year' => 2030]);
    $program = Program::factory()->create(['strategy_id' => $strategy->id]);
    YearlyPlan::factory()->create(['program_id' => $program->id, 'year' => 2026]);

    $this->actingAs($user)
        ->get(route('strategies.show', $strategy))
        ->assertOk()
        ->assertSeeText('Plan coverage')
        // The removed x-ui.timeline rendered the span as a formatted date range.
        ->assertDontSeeText('Jan 1, 2026');
});

test('the strategy detail stat row totals equal the sum across programs', function () {
    $user = User::factory()->create();
    $strategy = Strategy::factory()->create();
    $first = Program::factory()->create(['strategy_id' => $strategy->id]);
    $second = Program::factory()->create(['strategy_id' => $strategy->id]);
    Project::factory()->count(2)->create(['program_id' => $first->id]);
    Project::factory()->count(1)->create(['program_id' => $second->id]);
    YearlyPlan::factory()->create(['program_id' => $first->id]);

    $this->actingAs($user)
        ->get(route('strategies.show', $strategy))
        ->assertOk()
        // 2 programs, 3 projects total, 1 annual plan total.
        ->assertSeeText('3')
        ->assertSeeText('1');
});
