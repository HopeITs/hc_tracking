<?php

use App\Enums\UserRole;
use App\Models\Program;
use App\Models\Strategy;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected from program management', function () {
    $this->get(route('programs.index'))->assertRedirect(route('login'));
});

test('all authenticated roles can access program management while permissions are deferred', function (UserRole $role) {
    $user = User::factory()->role($role)->create();

    $this->actingAs($user)
        ->get(route('programs.index'))
        ->assertOk()
        ->assertSeeText('Programs')
        ->assertSee(route('programs.index'), false);
})->with([
    'officer' => UserRole::Officer,
    'manager' => UserRole::Manager,
    'meal' => UserRole::Meal,
    'admin' => UserRole::Admin,
]);

test('authenticated users can search and filter programs by strategy and manager', function () {
    $user = User::factory()->create();
    $firstManager = User::factory()->create(['name' => 'Layla Haddad', 'email' => 'layla@example.com']);
    $secondManager = User::factory()->create(['name' => 'Omar Khalil', 'email' => 'omar@example.com']);
    $firstStrategy = Strategy::factory()->create(['name' => 'Country Strategy', 'start_year' => 2026, 'end_year' => 2030]);
    $secondStrategy = Strategy::factory()->create(['name' => 'Regional Strategy', 'start_year' => 2031, 'end_year' => 2035]);

    Program::factory()->create([
        'name' => 'Water Security',
        'strategy_id' => $firstStrategy->id,
        'manager_id' => $firstManager->id,
    ]);
    Program::factory()->create([
        'name' => 'Safe Shelter',
        'strategy_id' => $secondStrategy->id,
        'manager_id' => $secondManager->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::programs.index')
        ->assertSee('Water Security')
        ->assertSee('Safe Shelter')
        ->set('search', 'Layla')
        ->assertSee('Water Security')
        ->assertDontSee('Safe Shelter')
        ->call('clearFilters')
        ->set('strategy', (string) $secondStrategy->id)
        ->assertDontSee('Water Security')
        ->assertSee('Safe Shelter')
        ->call('clearFilters')
        ->set('manager', (string) $firstManager->id)
        ->assertSee('Water Security')
        ->assertDontSee('Safe Shelter')
        ->call('clearFilters')
        ->assertSet('search', '')
        ->assertSet('strategy', '')
        ->assertSet('manager', '')
        ->assertSee('Water Security')
        ->assertSee('Safe Shelter');
});

test('authenticated users can create a trimmed program with any staff member as direct manager', function () {
    $user = User::factory()->create();
    $strategy = Strategy::factory()->create();
    $officer = User::factory()->role(UserRole::Officer)->create();

    Livewire::actingAs($user)
        ->test('pages::programs.index')
        ->call('openCreateModal')
        ->set('name', '  Community Resilience  ')
        ->set('strategyId', (string) $strategy->id)
        ->set('managerId', (string) $officer->id)
        ->call('saveProgram')
        ->assertHasNoErrors()
        ->assertSet('showProgramModal', false);

    $program = Program::query()->where('name', 'Community Resilience')->sole();

    expect($program->strategy_id)->toBe($strategy->id)
        ->and($program->manager_id)->toBe($officer->id);
});

test('authenticated users can rename relink and reassign a program', function () {
    $user = User::factory()->create();
    $oldStrategy = Strategy::factory()->create();
    $newStrategy = Strategy::factory()->create();
    $oldManager = User::factory()->create();
    $newManager = User::factory()->create();
    $program = Program::factory()->create([
        'name' => 'Original Program',
        'strategy_id' => $oldStrategy->id,
        'manager_id' => $oldManager->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::programs.index')
        ->call('openEditModal', $program->id)
        ->assertSet('name', 'Original Program')
        ->assertSet('strategyId', (string) $oldStrategy->id)
        ->assertSet('managerId', (string) $oldManager->id)
        ->set('name', 'Updated Program')
        ->set('strategyId', (string) $newStrategy->id)
        ->set('managerId', (string) $newManager->id)
        ->call('saveProgram')
        ->assertHasNoErrors()
        ->assertSet('showProgramModal', false);

    expect($program->refresh())
        ->name->toBe('Updated Program')
        ->strategy_id->toBe($newStrategy->id)
        ->manager_id->toBe($newManager->id);
});

test('program fields are required and names cannot exceed 255 characters', function (string $name) {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::programs.index')
        ->call('openCreateModal')
        ->set('name', $name)
        ->call('saveProgram')
        ->assertHasErrors(['name', 'strategyId', 'managerId']);

    expect(Program::query()->count())->toBe(0);
})->with([
    'empty' => '',
    'too long' => str_repeat('A', 256),
]);

test('strategy and manager assignments must reference available records', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::programs.index')
        ->call('openCreateModal')
        ->set('name', 'Protection')
        ->set('strategyId', '999999')
        ->set('managerId', '999999')
        ->call('saveProgram')
        ->assertHasErrors(['strategyId' => 'exists', 'managerId' => 'exists'])
        ->assertSee('The selected strategy is no longer available.')
        ->assertSee('The selected manager is no longer available.');

    expect(Program::query()->count())->toBe(0);
});

test('program names can be reused across different strategic assignments', function () {
    $user = User::factory()->create();
    $strategy = Strategy::factory()->create();
    $manager = User::factory()->create();
    Program::factory()->create([
        'name' => 'Protection',
        'strategy_id' => $strategy->id,
        'manager_id' => $manager->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::programs.index')
        ->call('openCreateModal')
        ->set('name', 'Protection')
        ->set('strategyId', (string) $strategy->id)
        ->set('managerId', (string) $manager->id)
        ->call('saveProgram')
        ->assertHasNoErrors();

    expect(Program::query()->where('name', 'Protection')->count())->toBe(2);
});

test('program strategy and manager names are escaped in the directory', function () {
    $user = User::factory()->create();
    $unsafeProgram = '<script>alert("program")</script>';
    $unsafeStrategy = '<script>alert("strategy")</script>';
    $unsafeManager = '<script>alert("manager")</script>';
    $strategy = Strategy::factory()->create(['name' => $unsafeStrategy]);
    $manager = User::factory()->create(['name' => $unsafeManager]);
    Program::factory()->create([
        'name' => $unsafeProgram,
        'strategy_id' => $strategy->id,
        'manager_id' => $manager->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::programs.index')
        ->assertSee($unsafeProgram)
        ->assertSee($unsafeStrategy)
        ->assertSee($unsafeManager)
        ->assertDontSee($unsafeProgram, false)
        ->assertDontSee($unsafeStrategy, false)
        ->assertDontSee($unsafeManager, false);
});

test('program management has a clear empty state', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('programs.index'))
        ->assertOk()
        ->assertSeeText('No programs yet')
        ->assertSeeText('Add the first program and connect it to a strategy and manager.');
});
