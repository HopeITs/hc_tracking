<?php

use App\Models\User;

test('guests visiting the root url are sent straight to login', function () {
    $this->get(route('home'))->assertRedirect(route('login'));
});

test('authenticated users visiting the root url are sent to the dashboard', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertRedirect(route('dashboard'));
});
