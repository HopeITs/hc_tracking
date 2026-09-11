<?php

use App\Models\User;

test('the sidebar groups navigation under overview strategy and administration', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeTextInOrder(['Overview', 'Strategy', 'Administration']);
});

test('the sidebar does not render a group for routes that do not exist yet', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSeeText('Results & Learning')
        ->assertDontSeeText('Funding');
});

test('every sidebar link resolves to a registered route', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
})->throwsNoExceptions();
