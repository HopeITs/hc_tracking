<?php

use App\Models\Program;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Support\Facades\Date;

test('guests are redirected from the strategy portfolio', function () {
    $this->get(route('strategies.index'))->assertRedirect(route('login'));
});

test('authenticated users see active upcoming and completed strategies in planning order', function () {
    $user = User::factory()->create();
    Strategy::factory()->create([
        'name' => 'Completed country strategy',
        'start_year' => 2021,
        'end_year' => 2025,
    ]);
    Strategy::factory()->create([
        'name' => 'Upcoming country strategy',
        'start_year' => 2031,
        'end_year' => 2035,
    ]);
    $active = Strategy::factory()->create([
        'name' => 'Active country strategy',
        'start_year' => 2026,
        'end_year' => 2030,
    ]);

    $response = Date::withTestNow(
        '2026-06-15',
        fn () => $this->actingAs($user)->get(route('strategies.index')),
    );

    $response
        ->assertOk()
        ->assertSeeText('Strategies')
        ->assertSee(route('strategies.index'), false)
        ->assertSeeTextInOrder([
            'Active country strategy',
            'Upcoming country strategy',
            'Completed country strategy',
        ])
        ->assertSeeText('Active')
        ->assertSeeText('Upcoming')
        ->assertSeeText('Completed')
        // The redesign (P3) replaced the 3-dot mini-timeline (which carried an
        // aria-label of the period) with the shared x-ui.timeline component and a
        // compact list; both link to the strategy and show its years as text.
        ->assertSee(route('strategies.show', $active), false)
        ->assertSeeText('2026–2030');
});

test('the active status chip uses the good/green status tone, not the brand red', function () {
    $user = User::factory()->create();
    Strategy::factory()->create(['start_year' => 2026, 'end_year' => 2030]);

    $response = Date::withTestNow(
        '2026-06-15',
        fn () => $this->actingAs($user)->get(route('strategies.index')),
    );

    $response
        ->assertOk()
        ->assertSee('bg-status-good text-white', false)
        ->assertDontSee('bg-primary text-white', false);
});

test('the strategy portfolio search narrows the results', function () {
    $user = User::factory()->create();
    Strategy::factory()->create(['name' => 'Water Security Strategy', 'start_year' => 2026, 'end_year' => 2030]);
    Strategy::factory()->create(['name' => 'Shelter Strategy', 'start_year' => 2026, 'end_year' => 2030]);

    $this->actingAs($user)
        ->get(route('strategies.index', ['search' => 'Water']))
        ->assertOk()
        ->assertSeeText('Water Security Strategy')
        ->assertDontSeeText('Shelter Strategy');
});

test('the strategy portfolio status strip filters the results and counts correctly', function () {
    $user = User::factory()->create();
    Strategy::factory()->create(['name' => 'Active strategy', 'start_year' => 2026, 'end_year' => 2030]);
    Strategy::factory()->create(['name' => 'Upcoming strategy', 'start_year' => 2031, 'end_year' => 2035]);

    $response = Date::withTestNow(
        '2026-06-15',
        fn () => $this->actingAs($user)->get(route('strategies.index', ['status' => 'upcoming'])),
    );

    $response
        ->assertOk()
        ->assertSeeText('Upcoming strategy')
        ->assertDontSeeText('Active strategy');
});

test('the strategy portfolio timeline and list link to the strategy and show its program count', function () {
    $user = User::factory()->create();
    $strategy = Strategy::factory()->create(['name' => 'Water Security Strategy', 'start_year' => 2026, 'end_year' => 2030]);
    Program::factory()->create(['strategy_id' => $strategy->id]);
    Program::factory()->create(['strategy_id' => $strategy->id]);

    $this->actingAs($user)
        ->get(route('strategies.index'))
        ->assertOk()
        ->assertSee(route('strategies.show', $strategy), false)
        ->assertSeeText('2 programs');
});

test('the strategy portfolio explains when the current year has no active period', function () {
    $user = User::factory()->create();
    Strategy::factory()->create([
        'start_year' => 2021,
        'end_year' => 2025,
    ]);

    $response = Date::withTestNow(
        '2026-01-01',
        fn () => $this->actingAs($user)->get(route('strategies.index')),
    );

    $response
        ->assertOk()
        ->assertSeeText('No strategy currently covers 2026.');
});

test('a strategy remains active through its ending year', function () {
    $user = User::factory()->create();
    Strategy::factory()->create([
        'name' => 'Final-year country strategy',
        'start_year' => 2022,
        'end_year' => 2026,
    ]);

    $response = Date::withTestNow(
        '2026-12-31',
        fn () => $this->actingAs($user)->get(route('strategies.index')),
    );

    $response
        ->assertOk()
        ->assertSeeText('Final-year country strategy')
        ->assertSeeText('1 current period')
        ->assertDontSeeText('No strategy currently covers 2026.');
});

test('the strategy portfolio has a clear empty state', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('strategies.index'))
        ->assertOk()
        ->assertSeeText('No strategic periods yet')
        // With no strategies at all, "no active period" is meaningless noise.
        ->assertDontSeeText('No strategy currently covers');
});

test('strategy names are escaped in the portfolio', function () {
    $user = User::factory()->create();
    $unsafeName = '<script>alert("strategy")</script>';
    Strategy::factory()->create(['name' => $unsafeName]);

    $this->actingAs($user)
        ->get(route('strategies.index'))
        ->assertOk()
        ->assertSee($unsafeName)
        ->assertDontSee($unsafeName, false);
});
