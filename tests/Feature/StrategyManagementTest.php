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

test('the create, edit and delete modals show only a title, no description', function () {
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
        ->toContain(__('strategies.delete_this_strategy'))
        ->not->toContain(__('strategies.only_a_strategy_with_no_linked_programs_can_be_deleted'));
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
        ->call('deleteStrategy')
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
        ->assertSeeText(__('strategies.add_strategy'));
});

test('every role can open a strategy through the view icon, not the name', function (string $roleName) {
    $user = User::factory()->role($roleName)->create();
    $strategy = Strategy::factory()->create(['name' => 'Country Strategy']);

    $html = $this->actingAs($user)->get(route('strategies.index'))->getContent();

    // The view icon links to the strategy...
    expect($html)
        ->toContain(route('strategies.show', $strategy))
        ->toContain(__('common.view_name', ['name' => 'Country Strategy']))
        // ...but the name itself is plain text, not a link.
        ->not->toContain('<a href="'.route('strategies.show', $strategy).'" wire:navigate class="truncate font-semibold');
})->with([
    'team member' => 'Team Member',
    'meal' => 'MEAL',
    'manager' => 'Managers',
    'admin' => 'Super Admin',
]);

test('the strategy buttons follow their own permission, not create alone', function (string $permission, array $shown, array $hidden) {
    Strategy::factory()->create(['name' => 'Button strategy']);
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
    'update only' => ['strategies.update', ['edit-strategy-button'], ['create-strategy-button', 'delete-strategy-button']],
    'delete only' => ['strategies.delete', ['delete-strategy-button'], ['create-strategy-button', 'edit-strategy-button']],
]);
