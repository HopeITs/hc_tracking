<?php

use App\Models\Activity;
use App\Models\User;
use Livewire\Livewire;

test('a user can opt in to desktop popups and the page tells the script', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->create());

    $this->actingAs($user)->get(route('dashboard'))->assertSeeHtml('data-notification-popups="off"');

    Livewire::actingAs($user)->test('pages::notifications.settings')
        ->assertSet('popups', false)
        ->set('popups', true);

    expect($user->fresh()->notification_popups)->toBeTrue();

    $this->actingAs($user->fresh())->get(route('dashboard'))->assertSeeHtml('data-notification-popups="on"');
});

test('notification settings live at /notifications-settings, linked from the bell, not under /settings', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->create());

    expect(route('notification-settings.edit', absolute: false))->toBe('/notifications-settings');

    $this->actingAs($user)->get('/notifications-settings')
        ->assertOk()
        ->assertSeeText(__('notifications.popups_label'))
        ->assertSeeHtml('data-test="notification-popup-test"')
        ->assertSeeHtml("document.body.dataset.notificationPopups = \$event.target.checked ? 'on' : 'off'");

    $this->actingAs($user)->get(route('dashboard'))->assertSee(route('notification-settings.edit'));
    $this->actingAs($user)->get('/settings/notifications')->assertNotFound();
});

test('guests cannot open notification settings', function () {
    $this->get(route('notification-settings.edit'))->assertRedirect(route('login'));
});
