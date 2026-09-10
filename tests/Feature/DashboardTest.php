<?php

use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users see the application navigation on the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response
        ->assertSee('data-navigation-sidebar', false)
        ->assertSee('data-mobile-navigation-close', false)
        ->assertSee('data-mobile-navigation-toggle', false)
        ->assertSeeText('Overview')
        ->assertSeeText('Dashboard')
        ->assertSeeText('Settings')
        ->assertSeeText('Profile')
        ->assertSeeText('Appearance')
        ->assertSeeText('Security')
        ->assertDontSeeText('Repository')
        ->assertDontSeeText('Documentation');
});
