<?php

use App\Models\User;
use App\Support\Desk\AskCommands;
use App\Support\Desk\QuickAccess;
use Livewire\Livewire;

test('the desk shows no quick access button until something is kept there', function () {
    $user = User::factory()->role('Financial')->create();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-quick-access', false)
        ->assertSee('dk-quick-none', false)
        ->assertDontSee('data-quick-toggle', false);
});

test('a page and an action dropped on quick access are kept in order and listed under the button', function () {
    $user = User::factory()->role('Financial')->create();

    Livewire::actingAs($user)->test('pages::desk.index')
        ->call('addQuickAccess', 'go:transfers.index')
        ->call('addQuickAccess', 'new:transfer')
        ->assertSee('data-quick-toggle', false)
        ->assertSeeHtmlInOrder(['data-quick-key="go:transfers.index"', 'data-quick-key="new:transfer"'])
        ->assertSee(route('transfers.index'), false);

    expect($user->fresh()->desk_quick_access)->toBe(['go:transfers.index', 'new:transfer']);
});

test('quick access refuses what the viewer may not open, what is already there and a full list', function () {
    $user = User::factory()->role('Media Team')->create();
    $quick = app(QuickAccess::class);

    expect($quick->add($user, 'go:transfers.index'))->toBeFalse()
        ->and($quick->add($user, 'go:no.such.page'))->toBeFalse()
        ->and($quick->add($user, 'new:task'))->toBeTrue()
        ->and($quick->add($user, 'new:task'))->toBeFalse()
        ->and($user->fresh()->desk_quick_access)->toBe(['new:task']);

    $offered = array_map(fn (array $page): string => 'go:'.$page['route'], app(AskCommands::class)->for($user)['pages']);
    $user->forceFill(['desk_quick_access' => array_slice($offered, 0, QuickAccess::MaxEntries)])->save();

    expect($quick->add($user, 'new:task'))->toBeFalse()
        ->and($user->fresh()->desk_quick_access)->toHaveCount(QuickAccess::MaxEntries);
});

test('a page the viewer can no longer open drops out of the list but keeps its saved place', function () {
    $user = User::factory()->role('Media Team')->create(['desk_quick_access' => ['go:transfers.index', 'new:task']]);
    $quick = app(QuickAccess::class);

    expect(array_column($quick->for($user), 'key'))->toBe(['new:task']);

    $quick->reorder($user, ['new:task']);

    expect($user->fresh()->desk_quick_access)->toBe(['new:task', 'go:transfers.index']);
});

test('dragging rows saves the new order and the × removes one', function () {
    $user = User::factory()->role('Financial')->create(['desk_quick_access' => ['go:transfers.index', 'new:transfer']]);

    Livewire::actingAs($user)->test('pages::desk.index')
        ->call('sortQuickAccess', ['new:transfer', 'go:transfers.index', 'go:forged'])
        ->assertSeeHtmlInOrder(['data-quick-key="new:transfer"', 'data-quick-key="go:transfers.index"']);

    expect($user->fresh()->desk_quick_access)->toBe(['new:transfer', 'go:transfers.index']);

    Livewire::actingAs($user->fresh())->test('pages::desk.index')
        ->call('removeQuickAccess', 'new:transfer')
        ->assertDontSee('data-quick-key="new:transfer"', false);

    expect($user->fresh()->desk_quick_access)->toBe(['go:transfers.index']);
});

test('removing the last entry clears the saved list and hides the button', function () {
    $user = User::factory()->role('Financial')->create(['desk_quick_access' => ['go:transfers.index']]);

    Livewire::actingAs($user)->test('pages::desk.index')
        ->call('removeQuickAccess', 'go:transfers.index')
        ->assertDontSee('data-quick-toggle', false);

    expect($user->fresh()->desk_quick_access)->toBeNull();
});

test('each of the first nine rows opens with its number key, in the list order', function () {
    $user = User::factory()->role('Financial')->create(['desk_quick_access' => ['new:transfer', 'go:transfers.index']]);

    Livewire::actingAs($user)->test('pages::desk.index')
        ->assertSeeHtmlInOrder(['data-quick-key="new:transfer"', 'aria-keyshortcuts="1"', 'data-quick-key="go:transfers.index"', 'aria-keyshortcuts="2"']);
});

test('a button put on the desk in any way lands in quick access and never in the desk layout', function () {
    $user = User::factory()->role('Financial')->create(['desk_layout' => [['panel' => 'needs', 'size' => 'XL', 'cols' => 12, 'rows' => 4]]]);
    $desk = Livewire::actingAs($user)->test('pages::desk.index');

    $desk->call('addItem', 'a-transfer')->assertReturned(true);
    $desk->call('addRoute', 'security.edit')->assertReturned('g-security-edit');
    $desk->call('dropItem', 'a-agreement', [['panel' => 'needs', 'cols' => 6, 'rows' => 4], ['panel' => 'a-agreement', 'cols' => 6, 'rows' => 4]]);
    $desk->call('saveLayout', [['panel' => 'a-account', 'size' => 'S', 'cols' => 6, 'rows' => 1], ['panel' => 'needs', 'size' => 'L', 'cols' => 6, 'rows' => 4]]);

    $stored = array_column($user->fresh()->desk_layout, 'panel');

    expect($stored)->toBe(['needs'])
        ->and($user->fresh()->desk_quick_access)->toBe(['new:transfer', 'go:security.edit', 'new:agreement', 'new:account']);
});

test('the undo of a button put in quick access takes it out again', function () {
    $user = User::factory()->role('Financial')->create();

    Livewire::actingAs($user)->test('pages::desk.index')
        ->call('addQuickItem', 'a-transfer')
        ->assertReturned(true)
        ->assertDispatched('dk-item-placed', panel: 'a-transfer', quick: true)
        ->assertSee('data-quick-key="new:transfer"', false)
        ->call('removeQuickItem', 'a-transfer')
        ->assertDontSee('data-quick-key="new:transfer"', false);

    expect($user->fresh()->desk_quick_access)->toBeNull();
});

test('a button the viewer may not use is refused by quick access', function () {
    $user = User::factory()->role('Media Team')->create();

    Livewire::actingAs($user)->test('pages::desk.index')
        ->call('addQuickItem', 'a-transfer')
        ->assertReturned(false)
        ->assertNotDispatched('dk-item-placed');

    expect($user->fresh()->desk_quick_access)->toBeNull();
});

test('buttons a desk saved earlier move into quick access on its first load, in their order', function () {
    $user = User::factory()->role('Financial')->create(['desk_quick_access' => ['go:transfers.index'], 'desk_layout' => [
        ['panel' => 'a-transfer', 'size' => 'S', 'cols' => 4, 'rows' => 1],
        ['panel' => 'g-calendar', 'size' => 'S', 'cols' => 4, 'rows' => 1],
        ['panel' => 'g-transfers', 'size' => 'S', 'cols' => 4, 'rows' => 1],
        ['panel' => 'money', 'size' => 'L', 'cols' => 8, 'rows' => 5],
        ['panel' => 'transfers', 'size' => 'L', 'cols' => 4, 'rows' => 5],
    ]]);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSeeInOrder(['data-quick-key="go:transfers.index"', 'data-quick-key="new:transfer"', 'data-quick-key="go:calendar.index"'], false);

    expect(array_map(fn (array $entry): array => [$entry['panel'], $entry['cols'], $entry['rows']], $user->fresh()->desk_layout))
        ->toBe([['money', 8, 5], ['transfers', 4, 5]])
        ->and($user->fresh()->desk_quick_access)->toBe(['go:transfers.index', 'new:transfer', 'go:calendar.index']);
});

test('add to desk puts a button in quick access and takes it out, never on the desk', function () {
    $user = User::factory()->role('Financial')->create(['desk_layout' => [['panel' => 'needs', 'size' => 'XL', 'cols' => 12, 'rows' => 4]]]);

    $this->actingAs($user)->postJson(route('desk.items.toggle'), ['item' => 'a-transfer'])
        ->assertOk()
        ->assertJson(['item' => 'a-transfer', 'placed' => true, 'quick' => true]);

    // Asking for a state twice never flips it back.
    $this->actingAs($user)->postJson(route('desk.items.toggle'), ['item' => 'a-transfer', 'placed' => true])->assertJson(['placed' => true, 'quick' => true]);

    expect($user->fresh()->desk_quick_access)->toBe(['new:transfer'])
        ->and(array_column($user->fresh()->desk_layout, 'panel'))->toBe(['needs']);

    $this->actingAs($user)->postJson(route('desk.items.toggle'), ['item' => 'a-transfer'])->assertJson(['placed' => false, 'quick' => true]);

    expect($user->fresh()->desk_quick_access)->toBeNull();
});

test('the customize sheet\'s buttons tab adds straight to quick access and hides what is already there', function () {
    $user = User::factory()->role('Financial')->create(['desk_quick_access' => ['new:transfer']]);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-quick-items="'.e(json_encode(['a-transfer'])).'"', false)
        ->assertSee('data-customize-buttons-note', false)
        ->assertSee("pick('a-agreement')", false);

    Livewire::actingAs($user)->test('pages::desk.index')->call('addQuickItem', 'a-agreement')->assertReturned(true);

    expect($user->fresh()->desk_quick_access)->toBe(['new:transfer', 'new:agreement'])
        ->and($user->fresh()->desk_layout)->toBeNull();
});

test('the ask bar counts buttons kept in quick access as already there', function () {
    $user = User::factory()->role('Financial')->create(['desk_quick_access' => ['new:transfer', 'go:calendar.index']]);

    expect(app(AskCommands::class)->for($user)['placed'])->toContain('a-transfer', 'g-calendar')
        ->and(app(QuickAccess::class)->itemIds($user))->toBe(['a-transfer', 'g-calendar']);
});
