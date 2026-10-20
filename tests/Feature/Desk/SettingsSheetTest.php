<?php

use App\Models\User;

test('a Super Admin sees all five groups, each admin row as a link', function () {
    $response = $this->actingAs(User::factory()->role('Super Admin')->create())
        ->get(route('dashboard'))
        ->assertOk();

    foreach (['you', 'people', 'meal', 'places', 'system'] as $group) {
        $response->assertSee('data-settings-group="'.$group.'"', false);
    }

    foreach (['admin.users.index', 'admin.cities.index', 'admin.accounts.index', 'strategies.index', 'admin.notification-settings.edit', 'activity.index'] as $route) {
        $response->assertSee('href="'.route($route).'"', false);
    }
});

test('a Team Member gets locked rows without a link for admin areas, and open rows for security and notifications', function () {
    $response = $this->actingAs(User::factory()->role('Team Member')->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('href="'.route('security.edit').'"', false)
        ->assertSee('href="'.route('notification-settings.edit').'"', false);

    foreach (['admin.users.index', 'admin.accounts.index', 'admin.notification-settings.edit'] as $route) {
        $response->assertSee('data-settings-row="'.$route.'"', false)
            ->assertDontSee('href="'.route($route).'"', false);
    }

    $response->assertSee('Needs the accounts.view permission.');
});
