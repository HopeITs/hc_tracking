<?php

use App\Models\Program;
use App\Models\Strategy;
use App\Models\User;

test('guests are redirected from strategy detail', function () {
    $strategy = Strategy::factory()->create();

    $this->get(route('strategies.show', $strategy))->assertRedirect(route('login'));
});

test('strategy detail shows the strategy its period and its programs', function () {
    $user = User::factory()->create();
    $manager = User::factory()->create(['name' => 'Layla Haddad']);
    $strategy = Strategy::factory()->create([
        'name' => 'Country Strategy',
        'start_year' => 2026,
        'end_year' => 2030,
    ]);
    Program::factory()->create([
        'name' => 'Water Security',
        'strategy_id' => $strategy->id,
        'manager_id' => $manager->id,
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
