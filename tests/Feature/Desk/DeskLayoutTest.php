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
    expect(deskPanelIds(User::factory()->role('Team Member')->create()))->toBe(['needs', 'overdue', 'flow', 'notes', 'tasks', 'coming', 'milestones', 'projects'])
        ->and(deskPanelIds(User::factory()->role('Super Admin')->create()))->toContain('glance', 'money', 'pipeline')
        ->and(deskPanelIds(User::factory()->role('Financial')->create()))->toBe(['needs', 'overdue', 'flow', 'money', 'transfers', 'deliverables', 'duties', 'projects']);
});

test('a role without a layout falls back to the Team Member one, minus what it may not see', function () {
    expect(deskPanelIds(User::factory()->create()))->toBe(['needs', 'overdue', 'notes']);
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
        ['panel' => 'tasks', 'size' => 'L', 'cols' => 6, 'rows' => 4],
        ['panel' => 'needs', 'size' => 'XL', 'cols' => 12, 'rows' => 4],
    ]);
});

test('every role opens on full rows, with no empty columns', function (string $role) {
    $row = 0;

    foreach (app(Panels::class)->layoutFor(User::factory()->role($role)->create()) as $entry) {
        $row += $entry['cols'];
        expect($row)->toBeLessThanOrEqual(12);
        $row %= 12;
    }

    expect($row)->toBe(0);
})->with(['General Manager', 'Public Relations Manager', 'Public Relations', 'MEAL', 'Financial', 'Managers', 'Media Team', 'Team Member']);

test('a free resize saves the exact cells, kept within the grid limits', function () {
    $user = User::factory()->role('Team Member')->create();
    $desk = Livewire::actingAs($user)->test('pages::desk.index');
    $saved = fn (): array => collect($user->fresh()->desk_layout)->firstWhere('panel', 'tasks');

    $desk->call('resizePanelTo', 'tasks', 5, 3);
    expect($saved())->toMatchArray(['cols' => 5, 'rows' => 3]);

    $desk->call('resizePanelTo', 'tasks', 1, 1);
    expect($saved())->toMatchArray(['cols' => Panels::MinCols, 'rows' => Panels::MinRows]);

    $desk->call('resizePanelTo', 'tasks', 99, 99);
    expect($saved())->toMatchArray(['cols' => Panels::MaxCols, 'rows' => Panels::MaxRows]);
});

test('pulling a card from its start edge pins its column, until it no longer fits or is moved', function () {
    $user = User::factory()->role('Team Member')->create();
    $desk = Livewire::actingAs($user)->test('pages::desk.index');
    $saved = fn (): array => collect($user->fresh()->desk_layout)->firstWhere('panel', 'tasks');

    $desk->call('resizePanelTo', 'tasks', 4, 4, 9)->assertSeeHtml('#dk-panel-tasks { --col: 9; }');
    expect($saved())->toMatchArray(['cols' => 4, 'col' => 9])
        ->and(collect(app(Panels::class)->layoutFor($user->fresh()))->firstWhere('panel', 'tasks')['col'])->toBe(9);

    $desk->call('resizePanelTo', 'tasks', 6, 4, 9);
    expect($saved())->not->toHaveKey('col');

    $desk->call('resizePanelTo', 'tasks', 4, 4, 9)->call('movePanel', 'tasks', 0);
    expect($saved())->not->toHaveKey('col');
});

test('a card saved narrower than the minimum opens at the minimum', function () {
    $user = User::factory()->role('Team Member')->create(['desk_layout' => [['panel' => 'tasks', 'size' => 'S', 'cols' => 1, 'rows' => 4]]]);

    expect(app(Panels::class)->layoutFor($user)[0]['cols'])->toBe(Panels::MinCols);
});

test('resizing, moving, removing, adding and resetting are saved on the user at once', function () {
    $user = User::factory()->role('Team Member')->create();
    $desk = Livewire::actingAs($user)->test('pages::desk.index');

    $desk->call('resizePanel', 'tasks', 'XL');
    expect(collect($user->fresh()->desk_layout)->firstWhere('panel', 'tasks'))->toMatchArray(['panel' => 'tasks', 'size' => 'XL', 'cols' => 12]);

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

test('the customize sheet no longer lists the panels a role may not see', function () {
    Livewire::actingAs(User::factory()->role('Team Member')->create())
        ->test('pages::desk.index')
        ->assertDontSeeHtml('data-locked-panel')
        ->assertDontSee(__('desk.panel.money.locked'));
});

test('the customize sheet says there is nothing to add once every allowed panel is placed', function () {
    $user = User::factory()->create();
    $allowed = array_keys(app(Panels::class)->availableTo($user));
    $user->desk_layout = array_map(fn (string $panel): array => ['panel' => $panel, 'size' => 'M'], $allowed);
    $user->save();

    expect(deskPanelIds($user))->toBe($allowed);

    Livewire::actingAs($user)
        ->test('pages::desk.index')
        ->assertSeeHtml('x-show="draft.length === '.count($allowed).'" data-nothing-to-add')
        ->assertSee(__('desk.nothing_to_add'));
});

test('the customize sheet saves its whole draft only on Done, dropping what the viewer may not place', function () {
    $user = User::factory()->role('Team Member')->create();
    $desk = Livewire::actingAs($user)->test('pages::desk.index')
        ->assertSeeHtml('data-customize-done')
        // A string style binding would overwrite x-show's display:none and list every panel as placed.
        ->assertSeeHtml("x-bind:style=\"{ order: position('needs') }\"")
        ->assertDontSeeHtml('wire:click="removePanel(')
        ->assertDontSeeHtml('wire:click="addPanel(');

    expect($user->fresh()->desk_layout)->toBeNull();

    $desk->call('saveLayout', [
        ['panel' => 'coming', 'size' => 'S'],
        ['panel' => 'money', 'size' => 'L'],
        ['panel' => 'tasks', 'size' => 'HUGE'],
        ['panel' => 'coming', 'size' => 'XL'],
    ]);

    expect($user->fresh()->desk_layout)->toEqual([
        ['panel' => 'coming', 'size' => 'S', 'cols' => 3, 'rows' => 4],
        ['panel' => 'tasks', 'size' => 'L', 'cols' => 6, 'rows' => 4],
    ]);

    $desk->call('saveLayout', null);

    expect($user->fresh()->desk_layout)->toBeNull();
});

test('the desk has no My day clock or density switch', function () {
    Livewire::actingAs(User::factory()->role('Team Member')->create())
        ->test('pages::desk.index')
        ->assertDontSeeHtml('data-desk-day')
        ->assertDontSeeHtml('data-desk-density');
});

test('the command strip has no Ask button and keeps the quick actions', function () {
    Livewire::actingAs(User::factory()->role('Super Admin')->create())
        ->test('pages::desk.index')
        ->assertDontSeeHtml('data-desk-start-ask')
        ->assertSeeHtml('data-desk-start-action="task"');
});

test('the customize sheet offers no ready layouts', function () {
    Livewire::actingAs(User::factory()->role('Team Member')->create())
        ->test('pages::desk.index')
        ->assertSeeHtml('data-desk-customize')
        ->assertDontSeeHtml('data-customize-ready')
        ->assertDontSeeHtml('data-ready-layout');
});

test('a card is sized only from its own edges, with no size controls in its menu or the customize sheet', function () {
    Livewire::actingAs(User::factory()->role('Team Member')->create())
        ->test('desk.panels.tasks', ['size' => 'L', 'gridCols' => 5, 'gridRows' => 3])
        ->assertSeeHtml('data-resize="start"')
        ->assertDontSeeHtml('deskGridPicker')
        ->assertDontSeeHtml('menuitemradio');

    Livewire::actingAs(User::factory()->role('Team Member')->create())
        ->test('pages::desk.index')
        ->assertDontSeeHtml('for="cz-tasks"');
});

test('the annual plans panel is gone, even from a layout saved before', function () {
    $user = User::factory()->role('Super Admin')->create(['desk_layout' => [['panel' => 'plans', 'size' => 'L'], ['panel' => 'needs', 'size' => 'L']]]);

    expect(app(Panels::class)->availableTo($user))->not->toHaveKey('plans')
        ->and(deskPanelIds($user))->toBe(['needs']);
});
