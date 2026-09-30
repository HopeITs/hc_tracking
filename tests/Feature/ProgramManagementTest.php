<?php

use App\Http\Middleware\SyncProgramsFromHr;
use App\Models\Program;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('guests are redirected from program management', function () {
    $this->get(route('programs.index'))->assertRedirect(route('login'));
});

test('all authenticated roles can access program management', function (string $roleName) {
    $user = User::factory()->role($roleName)->create();

    $this->actingAs($user)
        ->get(route('programs.index'))
        ->assertOk()
        ->assertSeeText('Programs')
        ->assertSee(route('programs.index'), false);
})->with([
    'team member' => 'Team Member',
    'manager' => 'Managers',
    'meal' => 'MEAL',
    'admin' => 'Super Admin',
]);

test('authenticated users can search and filter programs by strategy and manager', function () {
    $user = User::factory()->role('General Manager')->create();
    $firstManager = insertHrEmployee(firstNameEn: 'Layla', lastNameEn: 'Haddad');
    $secondManager = insertHrEmployee(firstNameEn: 'Omar', lastNameEn: 'Khalil');
    $firstStrategy = Strategy::factory()->create(['name' => 'Country Strategy', 'start_year' => 2026, 'end_year' => 2030]);
    $secondStrategy = Strategy::factory()->create(['name' => 'Regional Strategy', 'start_year' => 2031, 'end_year' => 2035]);

    Program::factory()->create([
        'name' => 'Water Security',
        'strategy_id' => $firstStrategy->id,
        'manager_id' => $firstManager,
    ]);
    Program::factory()->create([
        'name' => 'Safe Shelter',
        'strategy_id' => $secondStrategy->id,
        'manager_id' => $secondManager,
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
        ->set('manager', (string) $firstManager)
        ->assertSee('Water Security')
        ->assertDontSee('Safe Shelter')
        ->call('clearFilters')
        ->assertSet('search', '')
        ->assertSet('strategy', '')
        ->assertSet('manager', '')
        ->assertSee('Water Security')
        ->assertSee('Safe Shelter');
});

test('an hr program can be linked to a strategy and manager without changing its hr name', function () {
    $user = User::factory()->role('General Manager')->create();
    $strategy = Strategy::factory()->create();
    $manager = insertHrEmployee();
    $program = Program::factory()->unassigned()->create(['name' => 'Hope Center']);

    Livewire::actingAs($user)
        ->test('pages::programs.index')
        ->call('openEditModal', $program->id)
        ->assertSet('editingProgramName', 'Hope Center')
        ->assertSet('strategyId', '')
        ->assertSet('managerId', '')
        ->set('strategyId', (string) $strategy->id)
        ->set('managerId', (string) $manager)
        ->call('saveProgram')
        ->assertHasNoErrors()
        ->assertSet('showProgramModal', false);

    expect($program->refresh())
        ->name->toBe('Hope Center')
        ->strategy_id->toBe($strategy->id)
        ->manager_id->toBe($manager)
        ->and(DB::connection('hr')->table('programs')->where('id', $program->id)->value('name'))->toBe('Hope Center');
});

test('a program assignment can be cleared back to not assigned', function () {
    $program = Program::factory()->create();

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::programs.index')
        ->call('openEditModal', $program->id)
        ->set('strategyId', '')
        ->set('managerId', '')
        ->call('saveProgram')
        ->assertHasNoErrors();

    expect($program->refresh())
        ->strategy_id->toBeNull()
        ->manager_id->toBeNull();
});

test('unassigned programs are listed as not assigned', function () {
    Program::factory()->unassigned()->create(['name' => 'Study Zone']);

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::programs.index')
        ->assertSee('Study Zone')
        ->assertSee('Not assigned');
});

test('strategy and manager assignments must reference available records', function () {
    $program = Program::factory()->unassigned()->create();

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::programs.index')
        ->call('openEditModal', $program->id)
        ->set('strategyId', '999999')
        ->set('managerId', '999999')
        ->call('saveProgram')
        ->assertHasErrors(['strategyId' => 'exists', 'managerId' => 'exists'])
        ->assertSee('The selected strategy is no longer available.')
        ->assertSee('The selected manager is no longer available.');

    expect($program->refresh()->strategy_id)->toBeNull();
});

test('the directory offers no way to create programs', function () {
    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::programs.index')
        ->assertDontSee('Add program')
        ->assertDontSee('data-test="create-program-button"', false);
});

test('program strategy and manager names are escaped in the directory', function () {
    $user = User::factory()->role('General Manager')->create();
    $unsafeProgram = '<script>alert("program")</script>';
    $unsafeStrategy = '<script>alert("strategy")</script>';
    $unsafeManager = '<script>alert("manager")</script>';
    $strategy = Strategy::factory()->create(['name' => $unsafeStrategy]);
    $manager = insertHrEmployee(firstNameEn: $unsafeManager, lastNameEn: '');
    Program::factory()->create([
        'name' => $unsafeProgram,
        'strategy_id' => $strategy->id,
        'manager_id' => $manager,
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

test('program management explains that programs come from hr when there are none', function () {
    $this->actingAs(User::factory()->role('General Manager')->create())
        ->get(route('programs.index'))
        ->assertOk()
        ->assertSeeText('No programs from HR yet')
        ->assertSeeText('Programs appear here once they are added in the HR system.');
});

test('program cards are the default and link to their details', function () {
    $program = Program::factory()->create(['name' => 'Water Security']);

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::programs.index')
        ->assertSet('visibleProgramCount', 8)
        ->assertSee('Water Security')
        ->assertSee(route('programs.show', $program), false)
        ->assertDontSee('Programs come from the HR system.')
        ->assertDontSee('matching program');
});

test('program cards load eight initially and four more on request', function () {
    foreach (range(1, 9) as $number) {
        Program::factory()->create(['name' => 'Program '.str_pad((string) $number, 2, '0', STR_PAD_LEFT)]);
    }

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::programs.index')
        ->assertSet('visibleProgramCount', 8)
        ->assertSee('Program 08')
        ->assertDontSee('Program 09')
        ->assertSee('Load more')
        ->call('loadMore')
        ->assertSet('visibleProgramCount', 12)
        ->assertSee('Program 09');
});

test('program gap filter focuses programs without a manager', function () {
    Program::factory()->create(['name' => 'Managed Program']);
    Program::factory()->unassigned()->create(['name' => 'Unmanaged Program']);

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::programs.index')
        ->set('gap', 'no_manager')
        ->assertSee('Unmanaged Program')
        ->assertDontSee('Managed Program');
});

test('program filter pickers apply strategy and manager selections', function () {
    $strategy = Strategy::factory()->create(['name' => 'Country Strategy']);
    $manager = insertHrEmployee(firstNameEn: 'Amal', lastNameEn: 'Manager');
    Program::factory()->create(['name' => 'Selected Program', 'strategy_id' => $strategy->id, 'manager_id' => $manager]);
    Program::factory()->create(['name' => 'Other Program']);

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::programs.index')
        ->call('filterByStrategy', $strategy->id)
        ->assertSet('strategy', (string) $strategy->id)
        ->assertSee('Selected Program')
        ->assertDontSee('Other Program')
        ->call('filterByManager', $manager)
        ->assertSet('manager', (string) $manager)
        ->assertSee('Selected Program')
        ->assertDontSee('Other Program');
});

test('visiting the app mirrors new hr programs as unassigned', function () {
    Cache::forget(SyncProgramsFromHr::CacheKey);
    $hrProgramId = insertHrProgram('Children University');

    $this->actingAs(User::factory()->role('General Manager')->create())
        ->get(route('programs.index'))
        ->assertOk()
        ->assertSeeText('Children University');

    expect(Program::query()->findOrFail($hrProgramId))
        ->name->toBe('Children University')
        ->strategy_id->toBeNull()
        ->manager_id->toBeNull();
});
