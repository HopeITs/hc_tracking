<?php

use App\Models\Permission;
use App\Models\Program;
use App\Models\Role;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    Permission::query()->firstOrCreate(['name' => 'strategies.create', 'guard_name' => 'web']);
    Permission::query()->firstOrCreate(['name' => 'strategies.update', 'guard_name' => 'web']);
    Permission::query()->firstOrCreate(['name' => 'strategies.delete', 'guard_name' => 'web']);

    Role::findOrCreate('Managers', 'web')->givePermissionTo(['strategies.create', 'strategies.update', 'strategies.delete']);
    Role::findOrCreate('Super Admin', 'web')->givePermissionTo(['strategies.create', 'strategies.update', 'strategies.delete']);
    Role::findOrCreate('MEAL', 'web');
});

test('a manager can create a strategy and two strategies may share a name', function () {
    $manager = User::factory()->role('Managers')->create();
    Strategy::factory()->create(['name' => 'Country Strategy']);

    Livewire::actingAs($manager)
        ->test('pages::strategies.index')
        ->call('openCreateModal')
        ->set('name', '  Country Strategy  ')
        ->set('startYear', '2026')
        ->set('endYear', '2030')
        ->call('saveStrategy')
        ->assertHasNoErrors();

    expect(Strategy::query()->where('name', 'Country Strategy')->count())->toBe(2);

    $created = Strategy::query()->where('name', 'Country Strategy')->latest('id')->first();
    expect($created)->start_year->toBe(2026)->end_year->toBe(2030);
});

test('creating a strategy requires a name and a valid year span', function () {
    $manager = User::factory()->role('Managers')->create();

    Livewire::actingAs($manager)
        ->test('pages::strategies.index')
        ->call('openCreateModal')
        ->set('name', '')
        ->set('startYear', '2026')
        ->set('endYear', '2030')
        ->call('saveStrategy')
        ->assertHasErrors(['name']);

    Livewire::actingAs($manager)
        ->test('pages::strategies.index')
        ->call('openCreateModal')
        ->set('name', 'Backwards Strategy')
        ->set('startYear', '2030')
        ->set('endYear', '2026')
        ->call('saveStrategy')
        ->assertHasErrors(['endYear']);
});

test('typing a year after a failed save clears only that year error', function () {
    $manager = User::factory()->role('Managers')->create();

    Livewire::actingAs($manager)
        ->test('pages::strategies.index')
        ->call('openCreateModal')
        ->call('saveStrategy')
        ->assertHasErrors(['name', 'startYear', 'endYear'])
        ->set('startYear', '2027')
        ->assertHasNoErrors(['startYear'])
        ->assertHasErrors(['name', 'endYear'])
        ->set('endYear', '2028')
        ->assertHasNoErrors(['endYear']);
});

test('a manager can edit a strategy', function () {
    $manager = User::factory()->role('Managers')->create();
    $strategy = Strategy::factory()->create(['name' => 'Draft Strategy', 'start_year' => 2026, 'end_year' => 2028]);

    Livewire::actingAs($manager)
        ->test('pages::strategies.index')
        ->call('openEditModal', $strategy->id)
        ->assertSet('name', 'Draft Strategy')
        ->set('name', 'Renamed Strategy')
        ->set('endYear', '2030')
        ->call('saveStrategy')
        ->assertHasNoErrors();

    expect($strategy->refresh())->name->toBe('Renamed Strategy')->end_year->toBe(2030);
});

test('the create and edit modals show only a title; the delete modal names the strategy and says it is final', function () {
    $manager = User::factory()->role('Managers')->create();
    $strategy = Strategy::factory()->create();

    $component = Livewire::actingAs($manager)->test('pages::strategies.index');

    $createHtml = $component->call('openCreateModal')->html();
    $editHtml = $component->call('openEditModal', $strategy->id)->html();
    $deleteHtml = $component->call('confirmDelete', $strategy->id)->html();

    expect($createHtml)
        ->toContain(__('strategies.add_strategy'))
        ->not->toContain(__('strategies.strategy_names_need_not_be_unique'));
    expect($editHtml)
        ->toContain(__('strategies.edit_strategy'))
        ->not->toContain(__('strategies.strategy_names_need_not_be_unique'));
    expect($deleteHtml)
        ->toContain(e(__('strategies.delete_named_strategy', ['name' => $strategy->name])))
        ->toContain(e(__('strategies.delete_strategy_cannot_be_undone')));
});

test('confirming deletion opens the delete modal for the right strategy', function () {
    $manager = User::factory()->role('Managers')->create();
    $strategy = Strategy::factory()->create();

    // showDeleteModal is the property the confirm dialog's wire:model is bound
    // to (Flux's flux:modal has no reactive `open` prop, only wire:model), so
    // this is what actually makes the "yes/cancel" dialog appear.
    Livewire::actingAs($manager)
        ->test('pages::strategies.index')
        ->assertSet('showDeleteModal', false)
        ->call('confirmDelete', $strategy->id)
        ->assertSet('showDeleteModal', true)
        ->assertSet('deletingStrategyId', $strategy->id);
});

test('a strategy with no programs can be deleted', function () {
    $manager = User::factory()->role('Managers')->create();
    $strategy = Strategy::factory()->create();

    Livewire::actingAs($manager)
        ->test('pages::strategies.index')
        ->call('confirmDelete', $strategy->id)
        ->call('deleteStrategy')
        ->assertHasNoErrors()
        ->assertSet('showDeleteModal', false)
        ->assertSet('deletingStrategyId', null);

    expect(DB::table('strategies')->find($strategy->id))->toBeNull();
});

test('a strategy with linked programs is not deleted', function () {
    $manager = User::factory()->role('Managers')->create();
    $strategy = Strategy::factory()->create();
    Program::factory()->create(['strategy_id' => $strategy->id]);

    Livewire::actingAs($manager)
        ->test('pages::strategies.index')
        ->call('confirmDelete', $strategy->id)
        ->call('deleteStrategy')
        ->assertSet('showDeleteModal', false);

    expect(DB::table('strategies')->find($strategy->id))->not->toBeNull();
});

test('cancelling the delete confirmation closes the modal without deleting', function () {
    $manager = User::factory()->role('Managers')->create();
    $strategy = Strategy::factory()->create();

    Livewire::actingAs($manager)
        ->test('pages::strategies.index')
        ->call('confirmDelete', $strategy->id)
        ->set('showDeleteModal', false)
        ->assertSet('showDeleteModal', false);

    $this->assertModelExists($strategy);
});

test('admins can also manage strategies', function () {
    $admin = User::factory()->role('Super Admin')->create();

    Livewire::actingAs($admin)
        ->test('pages::strategies.index')
        ->call('openCreateModal')
        ->set('name', 'Admin Strategy')
        ->set('startYear', '2026')
        ->set('endYear', '2030')
        ->call('saveStrategy')
        ->assertHasNoErrors();

    expect(Strategy::query()->where('name', 'Admin Strategy')->exists())->toBeTrue();
});

test('team members and meal staff cannot manage strategies', function (string $roleName) {
    $user = User::factory()->role($roleName)->create();
    $strategy = Strategy::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::strategies.index')
        ->call('openCreateModal')
        ->assertForbidden();

    Livewire::actingAs($user)
        ->test('pages::strategies.index')
        ->call('openEditModal', $strategy->id)
        ->assertForbidden();

    Livewire::actingAs($user)
        ->test('pages::strategies.index')
        ->call('confirmDelete', $strategy->id)
        ->assertForbidden();

    $this->assertModelExists($strategy);
})->with([
    'team member' => 'Team Member',
    'meal' => 'MEAL',
]);

test('the add strategy control is hidden from team members and meal staff', function (string $roleName) {
    $user = User::factory()->role($roleName)->create();

    $this->actingAs($user)
        ->get(route('strategies.index'))
        ->assertOk()
        ->assertDontSee(__('strategies.new_strategy'))
        ->assertDontSee(__('strategies.add_strategy'));
})->with([
    'team member' => 'Team Member',
    'meal' => 'MEAL',
]);

test('the add strategy control is visible to managers', function () {
    $manager = User::factory()->role('Managers')->create();

    $this->actingAs($manager)
        ->get(route('strategies.index'))
        ->assertOk()
        ->assertSeeHtml('data-test="create-strategy-button"')
        ->assertSeeText(__('strategies.new_strategy'));
});

test('every role can open a strategy through a labelled Open link, not the name', function (string $roleName) {
    $user = User::factory()->role($roleName)->create();
    $strategy = Strategy::factory()->create(['name' => 'Country Strategy', 'start_year' => 2016, 'end_year' => 2020]);

    $html = $this->actingAs($user)->get(route('strategies.index'))->getContent();

    // The Open link carries a visible label and names the strategy for screen readers...
    expect($html)
        ->toContain('href="'.route('strategies.show', $strategy).'" wire:navigate.hover class="dk-slink" aria-label="'.__('strategies.open_named', ['name' => 'Country Strategy']).'">'.__('strategies.open'))
        // ...while the name itself is plain text, not a link.
        ->toContain('<strong>Country Strategy</strong>');
})->with([
    'team member' => 'Team Member',
    'meal' => 'MEAL',
    'manager' => 'Managers',
    'admin' => 'Super Admin',
]);

test('the strategy buttons follow their own permission, not create alone', function (string $permission, array $shown, array $hidden) {
    // A past strategy with nothing linked is the one row that may offer Delete.
    Strategy::factory()->create(['name' => 'Button strategy', 'start_year' => 2016, 'end_year' => 2020]);
    Role::create(['name' => 'Strategy Clerk', 'guard_name' => 'web'])->givePermissionTo($permission);
    $clerk = User::factory()->role('Strategy Clerk')->create();

    $page = Livewire::actingAs($clerk)->test('pages::strategies.index');

    foreach ($shown as $marker) {
        $page->assertSeeHtml('data-test="'.$marker.'"');
    }

    foreach ($hidden as $marker) {
        $page->assertDontSeeHtml('data-test="'.$marker.'"');
    }
})->with([
    'create only' => ['strategies.create', ['create-strategy-button'], ['edit-strategy-button', 'delete-strategy-button']],
    // Editing moved to the strategy page: the list shows no edit button, even with strategies.update.
    'update only' => ['strategies.update', [], ['edit-strategy-button', 'create-strategy-button', 'delete-strategy-button']],
    'delete only' => ['strategies.delete', ['delete-strategy-button'], ['create-strategy-button', 'edit-strategy-button']],
]);

test('the strategy form is rendered only while its modal is open', function () {
    $manager = User::factory()->role('Managers')->create();

    Livewire::actingAs($manager)
        ->test('pages::strategies.index')
        ->assertDontSeeHtml('wire:model.live.blur="startYear"')
        ->call('openCreateModal')
        ->assertSeeHtml('wire:model.live.blur="startYear"');
});
