<?php

use App\Enums\UserRole;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Strategy;
use App\Models\User;
use App\Models\YearlyPlan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Create a program whose row is identifiable by name.
 */
function matrixProgram(string $name): Program
{
    return Program::factory()->create([
        'name' => $name,
        'strategy_id' => Strategy::factory(),
    ]);
}

test('guests are redirected from the annual plan matrix', function () {
    $this->get(route('yearly-plans.index'))->assertRedirect(route('login'));
});

test('all authenticated roles can access the annual plan matrix while permissions are deferred', function (UserRole $role) {
    $user = User::factory()->role($role)->create();

    $this->actingAs($user)
        ->get(route('yearly-plans.index'))
        ->assertOk()
        ->assertSeeText('Annual Plans');
})->with([
    'officer' => UserRole::Officer,
    'manager' => UserRole::Manager,
    'meal' => UserRole::Meal,
    'admin' => UserRole::Admin,
]);

test('renders one row per program and one column per fiscal year in the span', function () {
    $user = User::factory()->create();
    $water = matrixProgram('Water Security');
    $shelter = matrixProgram('Safe Shelter');

    YearlyPlan::factory()->create(['program_id' => $water->id, 'year' => 2024]);
    YearlyPlan::factory()->create(['program_id' => $shelter->id, 'year' => 2026]);

    Livewire::actingAs($user)
        ->test('pages::yearly-plans.index')
        ->assertSee('Water Security')
        ->assertSee('Safe Shelter')
        ->assertSee('Fiscal years 2024–2026')
        ->assertSee('Water Security, 2025, no plan');
});

test('a planned cell names its manager and target count while an unplanned cell is marked empty', function () {
    $user = User::factory()->create();
    $manager = insertHrEmployee(firstNameEn: 'Layla', lastNameEn: 'Haddad');
    $water = matrixProgram('Water Security');

    $plan = YearlyPlan::factory()->create([
        'program_id' => $water->id,
        'manager_id' => $manager,
        'year' => 2026,
    ]);
    PlanIndicator::factory(3)->forYearlyPlan($plan)->create();

    YearlyPlan::factory()->create(['program_id' => matrixProgram('Safe Shelter')->id, 'year' => 2025]);

    Livewire::actingAs($user)
        ->test('pages::yearly-plans.index')
        ->assertSee('Water Security, 2026, managed by Layla Haddad, 3 targets')
        ->assertSee('Water Security, 2025, no plan');
});

test('filtering by assigned manager keeps only the plans that manager owns', function () {
    $user = User::factory()->create();
    $layla = insertHrEmployee(firstNameEn: 'Layla', lastNameEn: 'Haddad');
    $omar = insertHrEmployee(firstNameEn: 'Omar', lastNameEn: 'Khalil');
    $water = matrixProgram('Water Security');
    $shelter = matrixProgram('Safe Shelter');

    YearlyPlan::factory()->create(['program_id' => $water->id, 'manager_id' => $layla, 'year' => 2026]);
    YearlyPlan::factory()->create(['program_id' => $water->id, 'manager_id' => $omar, 'year' => 2025]);
    YearlyPlan::factory()->create(['program_id' => $shelter->id, 'manager_id' => $omar, 'year' => 2026]);

    Livewire::actingAs($user)
        ->test('pages::yearly-plans.index')
        ->set('manager', (string) $layla)
        ->assertSee('Water Security')
        ->assertDontSee('Safe Shelter')
        ->assertSee('Water Security, 2026, managed by Layla Haddad, 0 targets')
        ->assertSee('Water Security, 2025, no plan');
});

test('the manager filter only offers hr employees who are accountable for a plan', function () {
    $user = User::factory()->create();
    $planned = insertHrEmployee(firstNameEn: 'Layla', lastNameEn: 'Haddad');
    insertHrEmployee(firstNameEn: 'Unassigned', lastNameEn: 'Person');

    YearlyPlan::factory()->create(['manager_id' => $planned]);

    Livewire::actingAs($user)
        ->test('pages::yearly-plans.index')
        ->assertSee('Layla Haddad')
        ->assertDontSee('Unassigned Person');
});

test('the fiscal year range drops the columns outside it', function () {
    $user = User::factory()->create();
    $water = matrixProgram('Water Security');

    YearlyPlan::factory()->create(['program_id' => $water->id, 'year' => 2023]);
    YearlyPlan::factory()->create(['program_id' => matrixProgram('Safe Shelter')->id, 'year' => 2026]);

    Livewire::actingAs($user)
        ->test('pages::yearly-plans.index')
        ->assertSee('Water Security, 2026, no plan')
        ->set('yearFrom', '2026')
        ->assertSee('Fiscal years 2026–2026')
        ->assertSee('Water Security, 2026, no plan')
        ->assertDontSee('Water Security, 2023, no plan');
});

test('a reversed fiscal year range is read in order instead of rendering nothing', function () {
    $user = User::factory()->create();
    $water = matrixProgram('Water Security');

    YearlyPlan::factory()->create(['program_id' => $water->id, 'year' => 2024]);
    YearlyPlan::factory()->create(['program_id' => $water->id, 'year' => 2026]);

    Livewire::actingAs($user)
        ->test('pages::yearly-plans.index')
        ->set('yearFrom', '2026')
        ->set('yearTo', '2024')
        ->assertSee('Fiscal years 2024–2026')
        ->assertSee('Water Security, 2025, no plan');
});

test('a span wider than ten fiscal years is narrowed to the most recent ten', function () {
    $user = User::factory()->create();
    $water = matrixProgram('Water Security');

    YearlyPlan::factory()->create(['program_id' => $water->id, 'year' => 2010]);
    YearlyPlan::factory()->create(['program_id' => $water->id, 'year' => 2030]);

    Livewire::actingAs($user)
        ->test('pages::yearly-plans.index')
        ->assertSee('Fiscal years 2021–2030')
        ->assertSee('narrowed to 10 columns')
        ->assertDontSee('Water Security, 2020, no plan');
});

test('an explicit start year anchors the narrowed span at its beginning', function () {
    $user = User::factory()->create();
    $water = matrixProgram('Water Security');

    YearlyPlan::factory()->create(['program_id' => $water->id, 'year' => 2010]);
    YearlyPlan::factory()->create(['program_id' => $water->id, 'year' => 2030]);

    Livewire::actingAs($user)
        ->test('pages::yearly-plans.index')
        ->set('yearFrom', '2010')
        ->assertSee('Fiscal years 2010–2019');
});

test('a program with no plan keeps its row until the matrix is filtered by manager', function () {
    $user = User::factory()->create();
    $manager = User::factory()->create();
    matrixProgram('Unplanned Program');

    YearlyPlan::factory()->create([
        'program_id' => matrixProgram('Water Security')->id,
        'manager_id' => $manager->id,
        'year' => 2026,
    ]);

    Livewire::actingAs($user)
        ->test('pages::yearly-plans.index')
        ->assertSee('Unplanned Program')
        ->assertSee('Unplanned Program, 2026, no plan')
        ->set('manager', (string) $manager->id)
        ->assertDontSee('Unplanned Program');
});

test('a fiscal year column counts every plan in it, not only those on the current page', function () {
    $user = User::factory()->create();

    foreach (range(1, 17) as $index) {
        YearlyPlan::factory()->create([
            'program_id' => matrixProgram('Program '.str_pad((string) $index, 2, '0', STR_PAD_LEFT))->id,
            'year' => 2026,
        ]);
    }

    Livewire::actingAs($user)
        ->test('pages::yearly-plans.index')
        ->assertSee('(17)');
});

test('changing a filter returns the matrix to its first page', function () {
    $user = User::factory()->create();
    $manager = User::factory()->create();

    foreach (range(1, 16) as $index) {
        matrixProgram('Program '.str_pad((string) $index, 2, '0', STR_PAD_LEFT));
    }

    $lastProgram = Program::query()->orderByDesc('name')->firstOrFail();
    YearlyPlan::factory()->create([
        'program_id' => $lastProgram->id,
        'manager_id' => $manager->id,
        'year' => 2026,
    ]);

    Livewire::actingAs($user)
        ->test('pages::yearly-plans.index')
        ->call('setPage', 2)
        ->assertSee($lastProgram->name)
        ->set('manager', (string) $manager->id)
        ->assertSee($lastProgram->name);
});

test('resetting the filters restores the full span and every manager', function () {
    $user = User::factory()->create();
    $manager = User::factory()->create();
    $water = matrixProgram('Water Security');

    YearlyPlan::factory()->create(['program_id' => $water->id, 'manager_id' => $manager->id, 'year' => 2024]);
    YearlyPlan::factory()->create(['program_id' => matrixProgram('Safe Shelter')->id, 'year' => 2026]);

    Livewire::actingAs($user)
        ->test('pages::yearly-plans.index')
        ->set('yearFrom', '2024')
        ->set('yearTo', '2024')
        ->set('manager', (string) $manager->id)
        ->assertDontSee('Safe Shelter')
        ->call('clearFilters')
        ->assertSet('yearFrom', '')
        ->assertSet('yearTo', '')
        ->assertSet('manager', '')
        ->assertSee('Safe Shelter')
        ->assertSee('Fiscal years 2024–2026');
});

test('an empty matrix explains itself rather than rendering a grid', function () {
    $user = User::factory()->create();
    matrixProgram('Water Security');

    Livewire::actingAs($user)
        ->test('pages::yearly-plans.index')
        ->assertSee('No annual plans yet')
        ->assertDontSee('Water Security, 2026, no plan');
});

test('the matrix reads the plans of every visible cell in a fixed number of queries', function (int $programCount, int $yearCount) {
    $user = User::factory()->create();
    $manager = User::factory()->create();

    foreach (range(1, $programCount) as $index) {
        $program = matrixProgram('Program '.str_pad((string) $index, 2, '0', STR_PAD_LEFT));

        foreach (range(2020, 2019 + $yearCount) as $year) {
            YearlyPlan::factory()->create([
                'program_id' => $program->id,
                'manager_id' => $manager->id,
                'year' => $year,
            ]);
        }
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    Livewire::actingAs($user)->test('pages::yearly-plans.index');

    $planQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains($query['query'], 'yearly_plans'));

    DB::disableQueryLog();

    // The year options, the manager options, the cells and the column totals:
    // four reads, whatever the size of the grid. A per-cell lookup would make
    // this proportional to programs × years instead.
    expect($planQueries)->toHaveCount(4);
})->with([
    'a small grid' => [2, 2],
    'a full page' => [15, 8],
]);
