<?php

use App\Models\NotificationMute;
use Livewire\Livewire;

test('only modules I can see are offered for muting', function () {
    $financial = userWithRole('Financial');

    Livewire::actingAs($financial)->test('pages::notifications.settings')
        ->assertSeeText(__('notifications.modules.transfers'))
        ->assertDontSeeText(__('notifications.modules.reports'));
});

test('muting and unmuting a module', function () {
    $user = userWithRole('Managers');

    $page = Livewire::actingAs($user)->test('pages::notifications.settings')->call('toggleMute', 'tasks');

    expect(NotificationMute::query()->where('user_id', $user->id)->pluck('module')->all())->toBe(['tasks']);
    $page->assertSet('muted', ['tasks'])->call('toggleMute', 'tasks');
    expect(NotificationMute::query()->count())->toBe(0);
});

test('a module outside my permissions cannot be muted by a crafted call', function () {
    $user = userWithRole('Media Team');

    Livewire::actingAs($user)->test('pages::notifications.settings')->call('toggleMute', 'transfers');

    expect(NotificationMute::query()->count())->toBe(0);
});
