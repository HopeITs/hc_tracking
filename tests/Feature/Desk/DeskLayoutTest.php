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
    expect(deskPanelIds(User::factory()->role('Team Member')->create()))->toBe(['needs', 'overdue', 'flow', 'tasks', 'coming', 'milestones', 'projects'])
        ->and(deskPanelIds(User::factory()->role('Super Admin')->create()))->toContain('glance', 'money', 'pipeline')
        /* A department manager, so the deliverables panel is still offered (W-14, D-65). */
        ->and(deskPanelIds(User::factory()->role('Financial')->create(['employee_id' => insertHrDepartmentManager()])))->toBe(['needs', 'overdue', 'flow', 'money', 'transfers', 'deliverables', 'duties', 'projects']);
});

test('a role without a layout falls back to the Team Member one, minus what it may not see', function () {
    expect(deskPanelIds(User::factory()->create()))->toBe(['needs', 'overdue']);
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
        ['panel' => 'tasks', 'size' => 'XL', 'cols' => 12, 'rows' => 4],
        ['panel' => 'needs', 'size' => 'XL', 'cols' => 12, 'rows' => 4],
    ]);
});

test('a saved layout with holes opens flush: every row fills twelve columns at its tallest card\'s height', function () {
    $user = User::factory()->role('Team Member')->create(['desk_layout' => [
        ['panel' => 'tasks', 'size' => 'W', 'cols' => 7, 'rows' => 4],
        ['panel' => 'needs', 'size' => 'W', 'cols' => 7, 'rows' => 5],
        ['panel' => 'todo', 'size' => 'S', 'cols' => 3, 'rows' => 2],
        ['panel' => 'coming', 'size' => 'M', 'cols' => 4, 'rows' => 6],
    ]]);

    expect(array_map(fn (array $entry): array => [$entry['panel'], $entry['cols'], $entry['rows']], app(Panels::class)->layoutFor($user)))->toBe([
        ['tasks', 12, 4],
        ['needs', 8, 5],
        ['todo', 4, 5],
        ['coming', 12, 6],
    ]);
});

test('a short row shares the spare columns in proportion, never under the minimum, and a full row is left alone', function () {
    $panels = app(Panels::class);

    expect($panels->fitRow([4, 4]))->toBe([6, 6])
        ->and($panels->fitRow([3, 3, 3]))->toBe([4, 4, 4])
        ->and($panels->fitRow([7, 5]))->toBe([7, 5])
        ->and($panels->fitRow([12, 3]))->toBe([9, 3]);
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

test('a drag or pulled edge saves the arrangement it showed, kept within the grid limits', function () {
    $user = User::factory()->role('Team Member')->create();
    $desk = Livewire::actingAs($user)->test('pages::desk.index');
    $layout = app(Panels::class)->layoutFor($user);
    $shown = array_map(fn (array $entry): array => ['panel' => $entry['panel'], 'cols' => $entry['cols'], 'rows' => $entry['rows']], $layout);
    $shown[0] = ['panel' => 'needs', 'cols' => 8, 'rows' => 6];
    $shown[1] = ['panel' => 'overdue', 'cols' => 4, 'rows' => 6];

    $desk->call('arrangePanels', $shown);
    expect(array_slice(array_map(fn (array $entry): array => [$entry['panel'], $entry['cols'], $entry['rows']], app(Panels::class)->layoutFor($user->fresh())), 0, 2))
        ->toBe([['needs', 8, 6], ['overdue', 4, 6]]);

    $shown[0]['rows'] = 99;
    $shown[1]['rows'] = 99;
    $desk->call('arrangePanels', $shown);
    expect(collect($user->fresh()->desk_layout)->firstWhere('panel', 'needs'))->toMatchArray(['rows' => Panels::MaxRows]);
});

test('a card dropped into a full row is saved there, and the card it pushed opens the next row', function () {
    $user = User::factory()->role('Team Member')->create();
    $pushed = [
        ['panel' => 'needs', 'cols' => 3, 'rows' => 4],
        ['panel' => 'overdue', 'cols' => 3, 'rows' => 4],
        ['panel' => 'tasks', 'cols' => 3, 'rows' => 4],
        ['panel' => 'flow', 'cols' => 3, 'rows' => 4],
        ['panel' => 'coming', 'cols' => 6, 'rows' => 5],
        ['panel' => 'milestones', 'cols' => 6, 'rows' => 5],
        ['panel' => 'projects', 'cols' => 12, 'rows' => 4],
    ];

    Livewire::actingAs($user)->test('pages::desk.index')->call('arrangePanels', $pushed);

    expect(array_map(fn (array $entry): array => ['panel' => $entry['panel'], 'cols' => $entry['cols'], 'rows' => $entry['rows']], app(Panels::class)->layoutFor($user->fresh())))
        ->toBe($pushed);
});

test('a card grown past its neighbours is saved wider, with them wrapped to the start of the next row', function () {
    $user = User::factory()->role('Team Member')->create();
    $grown = [
        ['panel' => 'needs', 'cols' => 4, 'rows' => 3],
        ['panel' => 'overdue', 'cols' => 8, 'rows' => 3],
        ['panel' => 'flow', 'cols' => 4, 'rows' => 4],
        ['panel' => 'tasks', 'cols' => 4, 'rows' => 4],
        ['panel' => 'coming', 'cols' => 4, 'rows' => 4],
        ['panel' => 'milestones', 'cols' => 6, 'rows' => 4],
        ['panel' => 'projects', 'cols' => 6, 'rows' => 4],
    ];

    Livewire::actingAs($user)->test('pages::desk.index')->call('arrangePanels', $grown);

    expect(array_map(fn (array $entry): array => ['panel' => $entry['panel'], 'cols' => $entry['cols'], 'rows' => $entry['rows']], app(Panels::class)->layoutFor($user->fresh())))
        ->toBe($grown);
});

test('an arrangement that does not hold exactly the placed panels is ignored', function () {
    $user = User::factory()->role('Team Member')->create();
    $desk = Livewire::actingAs($user)->test('pages::desk.index');
    $shown = array_map(fn (array $entry): array => ['panel' => $entry['panel'], 'cols' => $entry['cols'], 'rows' => $entry['rows']], app(Panels::class)->layoutFor($user));

    $desk->call('arrangePanels', array_slice($shown, 1))
        ->call('arrangePanels', [...$shown, ['panel' => 'money', 'cols' => 12, 'rows' => 4]])
        ->call('arrangePanels', [...$shown, $shown[0]]);

    expect($user->fresh()->desk_layout)->toBeNull();
});

test('moving a card from its menu trades places, never shapes', function () {
    $user = User::factory()->role('Team Member')->create();
    $shape = fn (array $layout): array => array_map(fn (array $entry): array => [$entry['cols'], $entry['rows']], $layout);
    $before = app(Panels::class)->layoutFor($user);

    Livewire::actingAs($user)->test('pages::desk.index')->call('movePanel', 'projects', 0);
    $after = app(Panels::class)->layoutFor($user->fresh());

    expect($after[0]['panel'])->toBe('projects')
        ->and($shape($after))->toBe($shape($before));
});

test('a card saved narrower than the minimum opens at the minimum', function () {
    $user = User::factory()->role('Team Member')->create(['desk_layout' => [
        ['panel' => 'tasks', 'size' => 'S', 'cols' => 1, 'rows' => 4],
        ['panel' => 'needs', 'size' => 'W', 'cols' => 9, 'rows' => 4],
    ]]);

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

test('the customize sheet offers the To-do panel under Add, with the work panels', function () {
    Livewire::actingAs(User::factory()->role('Team Member')->create())
        ->test('pages::desk.index')
        ->assertSeeHtml('x-show="isAddable(\'todo\')" data-customize-add="todo"')
        ->assertSee(__('desk.customize_sheet.add_panel', ['panel' => __('desk.panel.todo.title')]));
});

test('the customize sheet says there is nothing to add once every allowed panel is placed and every button kept', function () {
    $user = User::factory()->create();
    $library = app(Panels::class)->availableTo($user);
    $user->desk_layout = array_map(fn (string $panel): array => ['panel' => $panel, 'size' => 'M'], array_keys($library));
    $user->save();

    $onDesk = array_keys(array_filter($library, fn (array $item): bool => in_array($item['kind'], ['panel', 'card'], true)));
    $buttons = array_keys(array_diff_key($library, array_flip($onDesk)));

    Livewire::actingAs($user)
        ->test('pages::desk.index')
        ->assertSeeHtml('x-show="search === \'\' && ! anyMatches()" data-nothing-to-add')
        ->assertSeeHtml('data-quick-items="'.e(json_encode($buttons)).'"')
        ->assertSee(__('desk.nothing_to_add'));

    expect(deskPanelIds($user->fresh()))->toBe($onDesk);
});

test('the customize sheet saves its whole draft only on Done, dropping what the viewer may not place', function () {
    $user = User::factory()->role('Team Member')->create();
    $desk = Livewire::actingAs($user)->test('pages::desk.index')
        ->assertSeeHtml('data-customize-done')
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

test('the desk has no quick-action strip', function () {
    Livewire::actingAs(User::factory()->role('Super Admin')->create())
        ->test('pages::desk.index')
        ->assertDontSeeHtml('data-desk-start')
        ->assertDontSeeHtml('data-desk-start-action');
});

test('the customize sheet offers the ready layouts', function () {
    Livewire::actingAs(User::factory()->role('Team Member')->create())
        ->test('pages::desk.index')
        ->assertSeeHtml('data-desk-customize')
        ->assertSeeHtml('data-customize-ready')
        ->assertSeeHtml('data-ready-layout="today"');
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
