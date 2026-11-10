<?php

use App\Models\Project;
use App\Models\User;
use App\Support\Desk\Panels;
use Livewire\Livewire;

/**
 * @return list<string>
 */
function placedItems(User $user): array
{
    return array_column(app(Panels::class)->layoutFor($user->fresh()), 'panel');
}

test('every page lands on the desk as its own panel, else its page card, else a button', function () {
    $panels = app(Panels::class);
    $admin = User::factory()->role('Super Admin')->create();

    expect($panels->itemForRoute($admin, 'projects.index'))->toBe('projects')
        ->and($panels->itemForRoute($admin, 'proposals.index'))->toBe('pipeline')
        ->and($panels->itemForRoute($admin, 'admin.evidence-types.index'))->toBe('p-admin-evidence-types')
        ->and($panels->itemForRoute($admin, 'funding-agreements.index'))->toBe('p-funding-agreements')
        ->and($panels->itemForRoute($admin, 'security.edit'))->toBe('g-security-edit')
        ->and($panels->itemForRoute($admin, 'dashboard'))->toBeNull();
});

test('page cards and buttons are offered exactly when their page is', function () {
    $panels = app(Panels::class);
    $teamMember = $panels->availableTo(User::factory()->role('Team Member')->create());
    $mediaTeam = $panels->availableTo(User::factory()->role('Media Team')->create());
    $meal = $panels->availableTo(User::factory()->role('MEAL')->create());

    expect($mediaTeam)->not->toHaveKeys(['p-admin-evidence-types', 'a-evidence-type', 'g-admin-evidence-types', 'p-admin-accounts', 'a-transfer'])
        ->and($mediaTeam)->toHaveKeys(['a-task', 'g-security-edit'])
        ->and($teamMember)->not->toHaveKey('a-task')
        ->and($meal)->toHaveKeys(['p-admin-evidence-types', 'a-evidence-type', 'g-admin-evidence-types'])
        ->and($meal['p-admin-evidence-types']['kind'])->toBe('card')
        ->and($meal['a-evidence-type']['kind'])->toBe('action')
        ->and($meal['g-admin-evidence-types']['kind'])->toBe('page');
});

test('a button may be one row unit tall, anything else at least two, and a row takes its tallest minimum', function () {
    $panels = app(Panels::class);

    expect($panels->sized('a-task', 'S', 3, 1)['rows'])->toBe(1)
        ->and($panels->sized('p-programs', 'L', 6, 1)['rows'])->toBe(Panels::MinRows)
        ->and($panels->sized('tasks', 'L', 6, 1)['rows'])->toBe(Panels::MinRows)
        ->and($panels->flush([$panels->sized('a-task', 'S', 6, 1), $panels->sized('g-security-edit', 'S', 6, 1)]))
        ->toBe([
            ['panel' => 'a-task', 'size' => 'L', 'cols' => 6, 'rows' => 1],
            ['panel' => 'g-security-edit', 'size' => 'L', 'cols' => 6, 'rows' => 1],
        ])
        ->and(array_column($panels->flush([$panels->sized('a-task', 'S', 6, 1), $panels->sized('tasks', 'L', 6, 4)]), 'rows'))->toBe([4, 4]);
});

test('quick actions have their own group, first under the Buttons tab of the Customize sheet', function () {
    $panels = app(Panels::class);

    expect($panels->readyKindOf('a-transfer'))->toBe('actions')
        ->and($panels->readyKindOf('g-security-edit'))->toBe('buttons')
        ->and($panels->readyKindOf('p-programs'))->toBe('pages');

    $html = $this->actingAs(User::factory()->role('Financial')->create())->get(route('dashboard'))->assertOk()->getContent();

    expect($html)->toMatch('/data-customize-tab="panels".*?data-customize-tab="cards".*?data-customize-tab="buttons"/s')
        ->and($html)->toMatch('/data-customize-group="money".*?data-customize-group="actions".*?data-customize-add="a-transfer".*?data-customize-group="buttons-/s');
});

test('a saved layout keeps the panels and cards the viewer may use, and never a button', function () {
    $user = User::factory()->role('Media Team')->create(['desk_layout' => [
        ['panel' => 'a-task', 'size' => 'S', 'cols' => 3, 'rows' => 1],
        ['panel' => 'p-admin-accounts', 'size' => 'L'],
        ['panel' => 'g-security-edit', 'size' => 'S', 'cols' => 3, 'rows' => 1],
        ['panel' => 'needs', 'size' => 'L'],
    ]]);

    expect(placedItems($user))->toBe(['needs']);
});

test('a sidebar page dropped on the desk is saved where the drop showed it', function () {
    $user = User::factory()->role('MEAL')->create(['desk_layout' => [
        ['panel' => 'needs', 'size' => 'L', 'cols' => 6, 'rows' => 4],
        ['panel' => 'overdue', 'size' => 'L', 'cols' => 6, 'rows' => 4],
    ]]);

    Livewire::actingAs($user)->test('pages::desk.index')
        ->call('dropItem', 'p-admin-evidence-types', [
            ['panel' => 'needs', 'cols' => 4, 'rows' => 4],
            ['panel' => 'p-admin-evidence-types', 'cols' => 4, 'rows' => 4],
            ['panel' => 'overdue', 'cols' => 4, 'rows' => 4],
        ])
        ->assertDispatched('dk-item-placed', panel: 'p-admin-evidence-types');

    expect(array_map(fn (array $entry): array => [$entry['panel'], $entry['cols'], $entry['rows']], app(Panels::class)->layoutFor($user->fresh())))
        ->toBe([['needs', 4, 4], ['p-admin-evidence-types', 4, 4], ['overdue', 4, 4]]);
});

test('a drop is ignored unless it adds exactly one new item the viewer may use', function () {
    $user = User::factory()->role('Team Member')->create(['desk_layout' => [['panel' => 'needs', 'size' => 'XL', 'cols' => 12, 'rows' => 4]]]);
    $desk = Livewire::actingAs($user)->test('pages::desk.index');

    // Forbidden for a team member, already placed, or carrying a second stowaway: nothing is saved.
    $desk->call('dropItem', 'p-admin-accounts', [['panel' => 'needs', 'cols' => 6, 'rows' => 4], ['panel' => 'p-admin-accounts', 'cols' => 6, 'rows' => 4]]);
    $desk->call('dropItem', 'needs', [['panel' => 'needs', 'cols' => 12, 'rows' => 4]]);
    $desk->call('dropItem', 'a-task', [['panel' => 'needs', 'cols' => 6, 'rows' => 4], ['panel' => 'a-task', 'cols' => 3, 'rows' => 4], ['panel' => 'overdue', 'cols' => 3, 'rows' => 4]]);

    expect(placedItems($user))->toBe(['needs']);
});

test('adding by route puts what stands for the page on the desk once, and a button in quick access', function () {
    $user = User::factory()->role('Super Admin')->create(['desk_layout' => [['panel' => 'needs', 'size' => 'XL', 'cols' => 12, 'rows' => 4]]]);
    $desk = Livewire::actingAs($user)->test('pages::desk.index');

    $desk->call('addRoute', 'admin.sectors.index')->assertReturned('p-admin-sectors');
    $desk->call('addRoute', 'admin.sectors.index')->assertReturned(null);
    $desk->call('addItem', 'a-task', 0)->assertReturned(true)->assertDispatched('dk-item-placed', panel: 'a-task', quick: true);

    expect(placedItems($user))->toBe(['needs', 'p-admin-sectors'])
        ->and($user->fresh()->desk_quick_access)->toBe(['new:task']);
});

test('the desk draws page cards beside its panels, and its buttons only in quick access', function () {
    $user = User::factory()->role('Super Admin')->create(['desk_layout' => [
        ['panel' => 'a-task', 'size' => 'S', 'cols' => 6, 'rows' => 1],
        ['panel' => 'g-security-edit', 'size' => 'S', 'cols' => 6, 'rows' => 1],
        ['panel' => 'p-admin-evidence-types', 'size' => 'L', 'cols' => 6, 'rows' => 4],
        ['panel' => 'needs', 'size' => 'L', 'cols' => 6, 'rows' => 4],
    ]]);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('data-panel="a-task"', false)
        ->assertDontSee('data-tile=', false)
        ->assertSee('data-quick-key="new:task"', false)
        ->assertSee(route('tasks.index', ['quick' => 'create']), false)
        ->assertSee('data-panel="p-admin-evidence-types"', false)
        ->assertSee('data-card="admin.evidence-types.index"', false)
        ->assertSee('class="dk-tabs"', false)
        ->assertSee('data-desk-routes', false);
});

test('add to desk puts an item on and takes it off, and only items the viewer may use', function () {
    $user = User::factory()->role('MEAL')->create(['desk_layout' => [['panel' => 'needs', 'size' => 'XL', 'cols' => 12, 'rows' => 4]]]);

    $this->actingAs($user)->postJson(route('desk.items.toggle'), ['route' => 'admin.evidence-types.index'])
        ->assertOk()
        ->assertJson(['item' => 'p-admin-evidence-types', 'placed' => true]);
    expect(placedItems($user))->toContain('p-admin-evidence-types');

    // Asking for a state twice never flips it back.
    $this->actingAs($user)->postJson(route('desk.items.toggle'), ['item' => 'p-admin-evidence-types', 'placed' => true])->assertJson(['placed' => true]);
    expect(placedItems($user))->toContain('p-admin-evidence-types');

    $this->actingAs($user)->postJson(route('desk.items.toggle'), ['item' => 'p-admin-evidence-types'])->assertJson(['placed' => false]);
    expect(placedItems($user))->not->toContain('p-admin-evidence-types');

    $this->actingAs($user)->postJson(route('desk.items.toggle'), ['item' => 'p-admin-accounts'])->assertStatus(422);
    $this->actingAs($user)->postJson(route('desk.items.toggle'), ['item' => 'nonsense'])->assertStatus(422);
    $this->actingAs($user)->postJson(route('desk.items.toggle'), [])->assertStatus(422);
});

test('a record page added from its tab puts its register on the desk', function () {
    $user = User::factory()->role('Super Admin')->create(['desk_layout' => [['panel' => 'needs', 'size' => 'XL', 'cols' => 12, 'rows' => 4]]]);
    $project = Project::factory()->create();

    $this->actingAs($user)->postJson(route('desk.items.toggle'), ['url' => route('projects.show', $project), 'placed' => true])
        ->assertOk()
        ->assertJson(['item' => 'projects', 'placed' => true]);
});

test('every register page offers "Add to desk" for what stands for it', function () {
    $this->actingAs(User::factory()->role('MEAL')->create())
        ->get(route('admin.evidence-types.index'))
        ->assertOk()
        ->assertSee('data-add-to-desk="admin.evidence-types.index"', false)
        ->assertSee('data-add-to-desk-choice="p-admin-evidence-types"', false)
        ->assertSee('data-add-to-desk-choice="g-admin-evidence-types"', false)
        ->assertSee('data-add-to-desk-choice="a-evidence-type"', false);
});
