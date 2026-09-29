<?php

use App\Models\User;

test('the header carries a tour button and the tour overlay with translated steps', function () {
    $user = User::factory()->role('Team Member')->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-test="tour-start-button"', false)
        ->assertSee('data-tour-root', false)
        ->assertSee('Your dashboard')
        ->assertSee('Every module, one sidebar');
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
