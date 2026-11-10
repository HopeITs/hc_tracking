<?php

use App\Models\User;
use App\Support\Desk\Panels;
use Livewire\Livewire;

function deskShape(array $layout): array
{
    return array_map(fn (array $entry): array => [$entry['cols'], $entry['rows']], $layout);
}

test('pinning a panel puts it first and every slot keeps its shape', function () {
    $user = User::factory()->role('Team Member')->create();
    $before = app(Panels::class)->layoutFor($user);
    $last = end($before)['panel'];

    Livewire::actingAs($user)->test('pages::desk.index')->call('pinPanel', $last);

    $after = app(Panels::class)->layoutFor($user->fresh());

    expect($after[0]['panel'])->toBe($last)
        ->and(deskShape($after))->toBe(deskShape($before))
        ->and($user->fresh()->desk_panel_flags)->toBe(['pinned' => [$last], 'folded' => []]);
});

test('pinned panels stay first in the order they were pinned, even after a drag puts another ahead', function () {
    $user = User::factory()->role('Team Member')->create();
    $panels = app(Panels::class);
    $order = array_column($panels->layoutFor($user), 'panel');
    [$first, $second] = [$order[count($order) - 1], $order[count($order) - 2]];
    $desk = Livewire::actingAs($user)->test('pages::desk.index')
        ->call('pinPanel', $first)
        ->call('pinPanel', $second);
    $pinned = $panels->layoutFor($user->fresh());
    $dragged = array_map(fn (array $slot, string $panel): array => ['panel' => $panel, 'cols' => $slot['cols'], 'rows' => $slot['rows']], $pinned, [$pinned[2]['panel'], $pinned[0]['panel'], $pinned[1]['panel'], ...array_column(array_slice($pinned, 3), 'panel')]);

    $desk->call('arrangePanels', $dragged);

    expect(array_slice(array_column($panels->layoutFor($user->fresh()), 'panel'), 0, 2))->toBe([$first, $second]);
});

test('unpinning a panel lets it fall back behind the panels still pinned', function () {
    $user = User::factory()->role('Team Member')->create();
    $panels = app(Panels::class);
    $order = array_column($panels->layoutFor($user), 'panel');
    [$first, $second] = [$order[count($order) - 1], $order[count($order) - 2]];

    Livewire::actingAs($user)->test('pages::desk.index')
        ->call('pinPanel', $first)
        ->call('pinPanel', $second)
        ->call('pinPanel', $first, false);

    expect(array_column($panels->layoutFor($user->fresh()), 'panel')[0])->toBe($second)
        ->and($user->fresh()->desk_panel_flags)->toBe(['pinned' => [$second], 'folded' => []]);
});

test('a collapsed panel is kept folded until opened, and forgotten once it leaves the desk', function () {
    $user = User::factory()->role('Team Member')->create();
    $panel = app(Panels::class)->layoutFor($user)[0]['panel'];
    $desk = Livewire::actingAs($user)->test('pages::desk.index')->call('foldPanel', $panel);

    expect($user->fresh()->desk_panel_flags)->toBe(['pinned' => [], 'folded' => [$panel]]);

    $desk->call('foldPanel', $panel, false);

    expect($user->fresh()->desk_panel_flags)->toBeNull();

    $desk->call('foldPanel', $panel)->call('removePanel', $panel);

    expect($user->fresh()->desk_panel_flags)->toBeNull();
});

test('a panel that is not on the desk cannot be pinned or folded', function () {
    $user = User::factory()->role('Team Member')->create();

    Livewire::actingAs($user)->test('pages::desk.index')
        ->call('pinPanel', 'money')
        ->call('foldPanel', 'no-such-panel');

    expect($user->fresh()->desk_panel_flags)->toBeNull();
});

test('resetting the desk clears what was pinned and folded', function () {
    $user = User::factory()->role('Team Member')->create();
    $panel = app(Panels::class)->layoutFor($user)[1]['panel'];

    Livewire::actingAs($user)->test('pages::desk.index')
        ->call('pinPanel', $panel)
        ->call('resetLayout');

    expect($user->fresh()->desk_panel_flags)->toBeNull()
        ->and($user->fresh()->desk_layout)->toBeNull();
});

test('flags saved in a broken shape are read as nothing pinned or folded', function () {
    $user = User::factory()->create(['desk_panel_flags' => ['pinned' => 'needs', 'folded' => [7, 'tasks', 'tasks']]]);

    expect(app(Panels::class)->flagsFor($user))->toBe(['pinned' => [], 'folded' => ['tasks']]);
});

test('the desk hands its scripts what is pinned and folded, and the panel menu pins and collapses instead of moving', function () {
    $user = User::factory()->role('Team Member')->create();
    $panel = app(Panels::class)->layoutFor($user)[1]['panel'];
    $user->forceFill(['desk_panel_flags' => ['pinned' => [$panel], 'folded' => [$panel, 'not-placed']]])->save();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-desk-flags>{"pinned":["'.$panel.'"],"folded":["'.$panel.'"]}</script>', false)
        ->assertSee('data-panel-pin', false)
        ->assertSee('data-panel-fold', false)
        ->assertSee('data-panel-unfold', false)
        ->assertDontSee(__('desk.move_earlier'))
        ->assertDontSee(__('desk.move_later'));
});

test('a panel with nothing to show is marked to fold itself', function () {
    $user = User::factory()->role('Super Admin')->create();

    Livewire::actingAs($user)->test('desk.panels.reviews', ['size' => 'L'])->assertSeeHtml('data-empty');
});

test('the to-do list folds itself while it is empty and stays open once it holds a to-do', function () {
    $user = User::factory()->role('Team Member')->create();

    Livewire::actingAs($user)->test('desk.panels.todo', ['size' => 'L'])
        ->assertSeeHtml('data-empty')
        ->call('add', 'Call the donor back')
        ->assertDontSeeHtml('data-empty');
});

test('the customize sheet can take every panel off the desk', function () {
    $user = User::factory()->role('Team Member')->create();

    Livewire::actingAs($user)->test('pages::desk.index')
        ->assertSeeHtml('data-customize-clear')
        ->call('saveLayout', []);

    expect($user->fresh()->desk_layout)->toBe([])
        ->and(app(Panels::class)->layoutFor($user->fresh()))->toBe([]);
});

test('ready layouts bring the page cards each job uses, and no buttons', function () {
    $user = User::factory()->role('Financial')->create();
    $money = collect(app(Panels::class)->readyLayoutsFor($user))->firstWhere('key', 'money');

    expect(array_column($money['entries'], 'panel'))->toContain('money', 'p-funding-agreements')
        ->not->toContain('a-transfer');
});

test('pinning a collapsed panel opens it at the top as a full panel', function () {
    $user = User::factory()->role('Team Member')->create();
    $layout = app(Panels::class)->layoutFor($user);
    $panel = end($layout)['panel'];

    Livewire::actingAs($user)->test('pages::desk.index')
        ->call('foldPanel', $panel)
        ->call('pinPanel', $panel);

    expect($user->fresh()->desk_panel_flags)->toBe(['pinned' => [$panel], 'folded' => []])
        ->and(app(Panels::class)->layoutFor($user->fresh())[0]['panel'])->toBe($panel);
});
