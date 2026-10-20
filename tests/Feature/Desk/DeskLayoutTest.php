<?php

use App\Models\User;
use App\Support\Desk\Panels;
use Livewire\Livewire;

/**
 * @return list<string>
 */
function deskPanelIds(User $user): array
{
    return array_column(app(Panels::class)->layoutFor($user), 'panel');
}

test('each role starts from its own layout, and a Super Admin from the General Manager one', function () {
    expect(deskPanelIds(User::factory()->role('Team Member')->create()))->toBe(['needs', 'tasks', 'coming', 'milestones', 'projects'])
        ->and(deskPanelIds(User::factory()->role('Super Admin')->create()))->toContain('glance', 'money', 'matrix')
        ->and(deskPanelIds(User::factory()->role('Financial')->create()))->toBe(['needs', 'money', 'transfers', 'deliverables', 'duties', 'projects']);
});

test('a role without a layout falls back to the Team Member one, minus what it may not see', function () {
    expect(deskPanelIds(User::factory()->create()))->toBe(['needs']);
});

test('a saved layout drops forbidden, unknown and repeated panels and fixes a bad size', function () {
    $user = User::factory()->role('Team Member')->create(['desk_layout' => [
        ['panel' => 'money', 'size' => 'L'],
        ['panel' => 'nonsense', 'size' => 'L'],
        ['panel' => 'tasks', 'size' => 'HUGE'],
        ['panel' => 'tasks', 'size' => 'S'],
        'not an entry',
        ['panel' => 'needs', 'size' => 'XL'],
    ]]);

    expect(app(Panels::class)->layoutFor($user))->toBe([
        ['panel' => 'tasks', 'size' => 'L'],
        ['panel' => 'needs', 'size' => 'XL'],
    ]);
});

test('resizing, moving, removing, adding and resetting are saved on the user at once', function () {
    $user = User::factory()->role('Team Member')->create();
    $desk = Livewire::actingAs($user)->test('pages::desk.index');

    $desk->call('resizePanel', 'tasks', 'XL');
    expect($user->fresh()->desk_layout[1])->toBe(['panel' => 'tasks', 'size' => 'XL']);

    $desk->call('movePanel', 'projects', 0);
    expect($user->fresh()->desk_layout[0]['panel'])->toBe('projects');

    $desk->call('removePanel', 'coming');
    expect(array_column($user->fresh()->desk_layout, 'panel'))->not->toContain('coming');

    $desk->call('addPanel', 'coming');
    expect(array_column($user->fresh()->desk_layout, 'panel'))->toContain('coming');

    $desk->call('resetLayout');
    expect($user->fresh()->desk_layout)->toBeNull();
});

test('a panel the viewer may not see cannot be added, and a bad size is ignored', function () {
    $user = User::factory()->role('Team Member')->create();
    $desk = Livewire::actingAs($user)->test('pages::desk.index');

    $desk->call('addPanel', 'money')->call('resizePanel', 'tasks', 'HUGE');

    expect($user->fresh()->desk_layout)->toBeNull();
});

test('the customize sheet lists locked panels with the reason', function () {
    Livewire::actingAs(User::factory()->role('Team Member')->create())
        ->test('pages::desk.index')
        ->assertSeeHtml('data-locked-panel="money"')
        ->assertSee(__('desk.panel.money.locked'))
        ->assertDontSeeHtml('data-locked-panel="needs"');
});
