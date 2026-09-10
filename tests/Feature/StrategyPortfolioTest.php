<?php

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
    Strategy::factory()->create([
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
        ->assertSeeText('Planning year 2026')
        ->assertSee('aria-label="Period 2026 to 2030"', false);
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
        ->assertSeeText('No strategic periods yet');
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
