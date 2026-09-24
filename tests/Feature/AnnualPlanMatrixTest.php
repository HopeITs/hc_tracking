<?php

use App\Models\AnnualPlan;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Strategy;
use App\Models\User;
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
    $this->get(route('annual-plans.index'))->assertRedirect(route('login'));
});

test('all authenticated roles can access the annual plan matrix while permissions are deferred', function (string $roleName) {
    $user = User::factory()->role($roleName)->create();

    $this->actingAs($user)
        ->get(route('annual-plans.index'))
        ->assertOk()
        ->assertSeeText('Annual Plans');
})->with([
    'officer' => 'Officer',
    'manager' => 'Managers',
    'meal' => 'MEAL',
    'admin' => 'Super Admin',
]);

test('renders one row per program and one column per fiscal year in the span', function () {
    $user = User::factory()->create();
    $water = matrixProgram('Water Security');
    $shelter = matrixProgram('Safe Shelter');

    AnnualPlan::factory()->create(['program_id' => $water->id, 'year' => 2024]);
    AnnualPlan::factory()->create(['program_id' => $shelter->id, 'year' => 2026]);

    Livewire::actingAs($user)
        ->test('pages::annual-plans.index')
        ->assertSee('Water Security')
        ->assertSee('Safe Shelter')
        ->assertSee('Fiscal years 2024–2026')
        ->assertSee('Water Security, 2025, no plan');
});

test('a planned cell names its manager and target count while an unplanned cell is marked empty', function () {
    $user = User::factory()->create();
    $manager = insertHrEmployee(firstNameEn: 'Layla', lastNameEn: 'Haddad');
    $water = matrixProgram('Water Security');

    $plan = AnnualPlan::factory()->create([
        'program_id' => $water->id,
        'manager_id' => $manager,
        'year' => 2026,
    ]);
    PlanIndicator::factory(3)->forAnnualPlan($plan)->create();

    AnnualPlan::factory()->create(['program_id' => matrixProgram('Safe Shelter')->id, 'year' => 2025]);

    Livewire::actingAs($user)
        ->test('pages::annual-plans.index')
        ->assertSee('Water Security, 2026, managed by Layla Haddad, 3 targets')
        ->assertSee('Water Security, 2025, no plan');
});

test('filtering by assigned manager keeps only the plans that manager owns', function () {
    $user = User::factory()->create();
    $layla = insertHrEmployee(firstNameEn: 'Layla', lastNameEn: 'Haddad');
    $omar = insertHrEmployee(firstNameEn: 'Omar', lastNameEn: 'Khalil');
    $water = matrixProgram('Water Security');
    $shelter = matrixProgram('Safe Shelter');

    AnnualPlan::factory()->create(['program_id' => $water->id, 'manager_id' => $layla, 'year' => 2026]);
    AnnualPlan::factory()->create(['program_id' => $water->id, 'manager_id' => $omar, 'year' => 2025]);
    AnnualPlan::factory()->create(['program_id' => $shelter->id, 'manager_id' => $omar, 'year' => 2026]);

    Livewire::actingAs($user)
        ->test('pages::annual-plans.index')
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

    AnnualPlan::factory()->create(['manager_id' => $planned]);

    Livewire::actingAs($user)
        ->test('pages::annual-plans.index')
        ->assertSee('Layla Haddad')
        ->assertDontSee('Unassigned Person');
});

test('selecting a fiscal year drops the columns outside the selection', function () {
    $user = User::factory()->create();
    $water = matrixProgram('Water Security');

    AnnualPlan::factory()->create(['program_id' => $water->id, 'year' => 2023]);
    AnnualPlan::factory()->create(['program_id' => matrixProgram('Safe Shelter')->id, 'year' => 2026]);

    Livewire::actingAs($user)
        ->test('pages::annual-plans.index')
        ->assertSee('Water Security, 2026, no plan')
        ->call('toggleYear', 2026)
        ->assertSet('years', [2026])
        ->assertSee('Fiscal years 2026')
        ->assertSee('Water Security, 2026, no plan')
        ->assertDontSee('Water Security, 2023, no plan');
});

test('years ticked out of order are still shown in ascending order', function () {
    $user = User::factory()->create();
    $water = matrixProgram('Water Security');

    AnnualPlan::factory()->create(['program_id' => $water->id, 'year' => 2024]);
    AnnualPlan::factory()->create(['program_id' => $water->id, 'year' => 2026]);

    Livewire::actingAs($user)
        ->test('pages::annual-plans.index')
        ->call('toggleYear', 2026)
        ->call('toggleYear', 2024)
        ->assertSet('years', [2024, 2026])
        // A gappy selection is listed, not described as a span: 2025 was not
        // asked for and has no column.
        ->assertSee('Fiscal years 2024, 2026')
        ->assertDontSee('Water Security, 2025, no plan');
});

test('a default span wider than ten fiscal years is narrowed to the most recent ten', function () {
    $user = User::factory()->create();
    $water = matrixProgram('Water Security');

    AnnualPlan::factory()->create(['program_id' => $water->id, 'year' => 2010]);
    AnnualPlan::factory()->create(['program_id' => $water->id, 'year' => 2030]);

    Livewire::actingAs($user)
        ->test('pages::annual-plans.index')
        ->assertSee('Fiscal years 2021–2030')
        ->assertSee('narrowed to 10 columns')
        ->assertDontSee('Water Security, 2020, no plan');
});

test('a selection of more than ten fiscal years keeps the most recent ten', function () {
    $user = User::factory()->create();
    $water = matrixProgram('Water Security');

    foreach (range(2010, 2030) as $year) {
        AnnualPlan::factory()->create(['program_id' => $water->id, 'year' => $year]);
    }

    $component = Livewire::actingAs($user)->test('pages::annual-plans.index');

    foreach (range(2010, 2022) as $year) {
        $component->call('toggleYear', $year);
    }

    // Thirteen years asked for, ten columns granted, and the ones kept are the
    // most recent of the selection rather than the first ticked.
    $component
        ->assertSee('Fiscal years 2013–2022')
        ->assertSee('narrowed to 10 columns')
        ->assertDontSee('Water Security, 2012, no plan');
});

test('a program with no plan keeps its row until the matrix is filtered by manager', function () {
    $user = User::factory()->create();
    $manager = User::factory()->create();
    matrixProgram('Unplanned Program');

    AnnualPlan::factory()->create([
        'program_id' => matrixProgram('Water Security')->id,
        'manager_id' => $manager->id,
        'year' => 2026,
    ]);

    Livewire::actingAs($user)
        ->test('pages::annual-plans.index')
        ->assertSee('Unplanned Program')
        ->assertSee('Unplanned Program, 2026, no plan')
        ->set('manager', (string) $manager->id)
        ->assertDontSee('Unplanned Program');
});

test('a fiscal year column counts every plan in it, not only those on the current page', function () {
    $user = User::factory()->create();

    foreach (range(1, 17) as $index) {
        AnnualPlan::factory()->create([
            'program_id' => matrixProgram('Program '.str_pad((string) $index, 2, '0', STR_PAD_LEFT))->id,
            'year' => 2026,
        ]);
    }

    Livewire::actingAs($user)
        ->test('pages::annual-plans.index')
        ->assertSee('(17)');
});

test('changing a filter returns the matrix to its first page', function () {
    $user = User::factory()->create();
    $manager = User::factory()->create();

    foreach (range(1, 16) as $index) {
        matrixProgram('Program '.str_pad((string) $index, 2, '0', STR_PAD_LEFT));
    }

    $lastProgram = Program::query()->orderByName('desc')->firstOrFail();
    AnnualPlan::factory()->create([
        'program_id' => $lastProgram->id,
        'manager_id' => $manager->id,
        'year' => 2026,
    ]);

    Livewire::actingAs($user)
        ->test('pages::annual-plans.index')
        ->call('setPage', 2)
        ->assertSee($lastProgram->name)
        ->set('manager', (string) $manager->id)
        ->assertSee($lastProgram->name);
});

test('resetting the filters restores the full span and every manager', function () {
    $user = User::factory()->create();
    $manager = User::factory()->create();
    $water = matrixProgram('Water Security');

    AnnualPlan::factory()->create(['program_id' => $water->id, 'manager_id' => $manager->id, 'year' => 2024]);
    AnnualPlan::factory()->create(['program_id' => matrixProgram('Safe Shelter')->id, 'year' => 2026]);

    Livewire::actingAs($user)
        ->test('pages::annual-plans.index')
        ->call('toggleYear', 2024)
        ->set('manager', (string) $manager->id)
        ->assertDontSee('Safe Shelter')
        ->call('clearFilters')
        ->assertSet('years', [])
        ->assertSet('manager', '')
        ->assertSee('Safe Shelter')
        ->assertSee('Fiscal years 2024–2026');
});

test('an empty matrix explains itself rather than rendering a grid', function () {
    $user = User::factory()->create();
    matrixProgram('Water Security');

    Livewire::actingAs($user)
        ->test('pages::annual-plans.index')
        ->assertSee('No annual plans yet')
        ->assertDontSee('Water Security, 2026, no plan');
});

test('the matrix reads the plans of every visible cell in a fixed number of queries', function (int $programCount, int $yearCount) {
    $user = User::factory()->create();
    $manager = User::factory()->create();

    foreach (range(1, $programCount) as $index) {
        $program = matrixProgram('Program '.str_pad((string) $index, 2, '0', STR_PAD_LEFT));

        foreach (range(2020, 2019 + $yearCount) as $year) {
            AnnualPlan::factory()->create([
                'program_id' => $program->id,
                'manager_id' => $manager->id,
                'year' => $year,
            ]);
        }
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    Livewire::actingAs($user)->test('pages::annual-plans.index');

    $planQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains($query['query'], 'annual_plans'));

    DB::disableQueryLog();

    // The year options, the manager options, the cells and the column totals:
    // four reads, whatever the size of the grid. A per-cell lookup would make
    // this proportional to programs × years instead.
    expect($planQueries)->toHaveCount(4);
})->with([
    'a small grid' => [2, 2],
    'a full page' => [15, 8],
]);

test('the cockpit lands on the current fiscal year when the span covers it', function () {
    $user = User::factory()->create();
    $thisYear = (int) now()->year;

    AnnualPlan::factory()->create(['program_id' => matrixProgram('Water Security')->id, 'year' => $thisYear - 1]);
    AnnualPlan::factory()->create(['program_id' => matrixProgram('Safe Shelter')->id, 'year' => $thisYear]);

    Livewire::actingAs($user)
        ->test('pages::annual-plans.index')
        ->assertSet('focusYear', '')
        ->assertSee('Focused fiscal year')
        ->assertSee('Focus fiscal year '.$thisYear.' — 1 plans, 0 targets');
});

test('focusing a fiscal year moves the cockpit without filtering the grid', function () {
    $user = User::factory()->create();
    $water = matrixProgram('Water Security');

    AnnualPlan::factory()->create(['program_id' => $water->id, 'year' => 2024]);
    AnnualPlan::factory()->create(['program_id' => matrixProgram('Safe Shelter')->id, 'year' => 2026]);

    Livewire::actingAs($user)
        ->test('pages::annual-plans.index')
        ->call('focusOnYear', 2024)
        ->assertSet('focusYear', '2024')
        // Both rows and the whole span stay on screen: focusing is a read.
        ->assertSee('Water Security')
        ->assertSee('Safe Shelter')
        ->assertSee('Fiscal years 2024–2026');
});

test('a focus year outside the visible span falls back to a column that exists', function () {
    $user = User::factory()->create();

    AnnualPlan::factory()->create(['program_id' => matrixProgram('Water Security')->id, 'year' => 2024]);
    AnnualPlan::factory()->create(['program_id' => matrixProgram('Safe Shelter')->id, 'year' => 2026]);

    Livewire::actingAs($user)
        ->test('pages::annual-plans.index')
        ->set('focusYear', '2026')
        ->call('toggleYear', 2024)
        ->assertSee('Focus fiscal year 2024 — 1 plans, 0 targets')
        ->assertDontSee('Focus fiscal year 2026');
});

test('a planned cell carries the targets committed in it and the year rail counts them', function () {
    $user = User::factory()->create();
    $water = matrixProgram('Water Security');

    $plan = AnnualPlan::factory()->create(['program_id' => $water->id, 'year' => 2026]);
    PlanIndicator::factory(4)->forAnnualPlan($plan)->create();

    Livewire::actingAs($user)
        ->test('pages::annual-plans.index')
        ->assertSee('Water Security, 2026, managed by')
        ->assertSee('4 targets')
        ->assertSee('Focus fiscal year 2026 — 1 plans, 4 targets');
});

test('filling the cells with delivery signal leaves the four reads of annual_plans untouched', function () {
    $user = User::factory()->create();

    foreach (range(1, 6) as $index) {
        $program = matrixProgram('Program '.str_pad((string) $index, 2, '0', STR_PAD_LEFT));

        foreach (range(2022, 2026) as $year) {
            $plan = AnnualPlan::factory()->create(['program_id' => $program->id, 'year' => $year]);
            PlanIndicator::factory(2)->forAnnualPlan($plan)->create();
        }
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    Livewire::actingAs($user)->test('pages::annual-plans.index');

    $planQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains($query['query'], 'annual_plans'));

    DB::disableQueryLog();

    expect($planQueries)->toHaveCount(4);
});

test('the program search narrows the matrix to programs whose name matches', function () {
    $user = User::factory()->create();

    AnnualPlan::factory()->create(['program_id' => matrixProgram('Water Security')->id, 'year' => 2026]);
    AnnualPlan::factory()->create(['program_id' => matrixProgram('Safe Shelter')->id, 'year' => 2026]);

    Livewire::actingAs($user)
        ->test('pages::annual-plans.index')
        ->set('programSearch', 'water')
        ->assertSee('Water Security')
        ->assertDontSee('Safe Shelter');
});

test('resetting the filters restores every program and clears the search', function () {
    $user = User::factory()->create();
    $water = matrixProgram('Water Security');

    AnnualPlan::factory()->create(['program_id' => $water->id, 'year' => 2026]);
    AnnualPlan::factory()->create(['program_id' => matrixProgram('Safe Shelter')->id, 'year' => 2026]);

    Livewire::actingAs($user)
        ->test('pages::annual-plans.index')
        ->set('programSearch', 'water')
        ->assertDontSee('Safe Shelter')
        ->call('clearFilters')
        ->assertSet('programSearch', '')
        ->assertSee('Safe Shelter');
});

test('searching for a program returns the matrix to its first page', function () {
    $user = User::factory()->create();

    foreach (range(1, 16) as $index) {
        matrixProgram('Program '.str_pad((string) $index, 2, '0', STR_PAD_LEFT));
    }

    AnnualPlan::factory()->create(['program_id' => Program::query()->first()->id, 'year' => 2026]);

    Livewire::actingAs($user)
        ->test('pages::annual-plans.index')
        ->call('setPage', 2)
        ->set('programSearch', 'Program')
        ->assertSee('Program 01');
});

test('a fiscal year can be unticked again', function () {
    $user = User::factory()->create();
    $water = matrixProgram('Water Security');

    AnnualPlan::factory()->create(['program_id' => $water->id, 'year' => 2024]);
    AnnualPlan::factory()->create(['program_id' => $water->id, 'year' => 2026]);

    Livewire::actingAs($user)
        ->test('pages::annual-plans.index')
        ->call('toggleYear', 2024)
        ->call('toggleYear', 2026)
        ->assertSet('years', [2024, 2026])
        ->call('toggleYear', 2024)
        ->assertSet('years', [2026])
        // Emptying the selection returns the grid to the full default span
        // rather than leaving it with no columns at all.
        ->call('toggleYear', 2026)
        ->assertSet('years', [])
        ->assertSee('Fiscal years 2024–2026');
});
