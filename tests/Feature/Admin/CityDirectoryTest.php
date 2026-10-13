<?php

use App\Models\Activity;
use App\Models\MealCoverageAssignment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('guests are redirected from the city directory', function () {
    $this->get(route('admin.cities.index'))->assertRedirect(route('login'));
});

test('all authenticated roles can view the city directory', function (string $roleName) {
    $user = User::factory()->role($roleName)->create();

    $this->actingAs($user)
        ->get(route('admin.cities.index'))
        ->assertOk()
        ->assertSeeText('Cities')
        ->assertSee(route('admin.cities.index'), false);
})->with([
    'team member' => 'Team Member',
    'manager' => 'Managers',
    'meal' => 'MEAL',
    'admin' => 'Super Admin',
]);

test('the directory lists every hr city in english and arabic, active or not', function () {
    insertHrCity('Damascus', 'دمشق');
    insertHrCity('Tartus', 'طرطوس', isActive: false);

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::admin.cities')
        ->assertSee('Damascus')
        ->assertSee('دمشق')
        ->assertSee('Tartus')
        ->assertSee('Inactive');
});

test('the directory searches english and arabic names and clears the search', function () {
    insertHrCity('Damascus', 'دمشق');
    insertHrCity('Aleppo', 'حلب');

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::admin.cities')
        ->set('view', 'grid') // the map keeps every city visible; this exercises the grid's own hide-on-search behaviour
        ->set('search', 'Damas')
        ->assertSee('Damascus')
        ->assertDontSee('Aleppo')
        ->set('search', 'حلب')
        ->assertSee('Aleppo')
        ->assertDontSee('Damascus')
        ->call('clearSearch')
        ->assertSet('search', '')
        ->assertSee('Damascus');
});

test('each city shows how many projects run in it', function () {
    $aleppo = insertHrCity('Aleppo');
    insertHrCity('Homs');
    Project::factory(2)->create(['city_id' => $aleppo]);

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::admin.cities')
        ->assertSee('2 projects')
        ->assertSee('0 projects');
});

test('city names are escaped in the directory', function () {
    $unsafeName = '<script>alert("city")</script>';
    insertHrCity($unsafeName);

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::admin.cities')
        ->assertSee($unsafeName)
        ->assertDontSee($unsafeName, false);
});

test('the directory offers no way to change hr cities', function () {
    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::admin.cities')
        ->assertDontSee('Add city')
        ->assertDontSee('data-test="create-city-button"', false);
});

test('the summary strip reports the portfolio footprint independent of the search', function () {
    $aleppo = insertHrCity('Aleppo');
    insertHrCity('Homs');
    insertHrCity('Tartus', 'طرطوس', isActive: false);
    Project::factory()->active()->create(['city_id' => $aleppo, 'target_beneficiaries' => 500]);
    Project::factory()->closed()->create(['city_id' => $aleppo, 'target_beneficiaries' => 300]);

    $component = Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::admin.cities')
        ->set('search', 'Homs'); // search narrows the grid, not the portfolio-wide summary

    expect($component->instance()->summary())->toBe([
        'totalCities' => 3,
        'activeCities' => 2,
        'coveredCities' => 1,
        'openProjects' => 1,
        'beneficiaries' => 800,
    ]);
});

test('the directory filters by active status', function () {
    insertHrCity('Damascus');
    insertHrCity('Tartus', 'طرطوس', isActive: false);

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::admin.cities')
        ->set('view', 'grid') // the map keeps every city visible; this exercises the grid's own hide-on-filter behaviour
        ->set('status', 'active')
        ->assertSee('Damascus')
        ->assertDontSee('Tartus')
        ->set('status', 'inactive')
        ->assertDontSee('Damascus')
        ->assertSee('Tartus')
        ->set('status', '')
        ->assertSee('Damascus')
        ->assertSee('Tartus');
});

test('the directory sorts cities by project count', function () {
    $aleppo = insertHrCity('Aleppo');
    $homs = insertHrCity('Homs');
    Project::factory(3)->create(['city_id' => $aleppo]);
    Project::factory(1)->create(['city_id' => $homs]);

    $component = Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::admin.cities')
        ->set('sort', 'projects');

    $names = $component->instance()->cities()->pluck('name')->all();

    expect($names)->toBe(['Aleppo', 'Homs']);
});

test('a city links straight to its filtered project list', function () {
    $aleppo = insertHrCity('Aleppo');

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::admin.cities')
        ->assertSee(route('projects.index', ['city' => $aleppo]), false);
});

test('the map view plots every city with a real link, even one the search would hide in the grid', function () {
    insertHrCity('Aleppo');
    $homs = insertHrCity('Homs');

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::admin.cities')
        ->set('view', 'map')
        ->set('search', 'Homs')
        ->assertSee('Aleppo') // dimmed on the map, but never removed from it
        ->assertSee('Homs')
        ->assertSee(route('projects.index', ['city' => $homs]), false);
});

test('a city with no plotted position falls into the "not shown on map" tray', function () {
    insertHrCity('Neverland');

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::admin.cities')
        ->set('view', 'map')
        ->assertSee('Neverland')
        ->assertSee(__('cities.not_shown_on_map'));
});

test('the coverage map is the default view', function () {
    insertHrCity('Aleppo');

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::admin.cities')
        ->assertSet('view', 'map')
        ->assertSee(__('cities.coverage_map'));
});

test('each governorate shows its meal officer in the grid and on the map, flagging an officer inactive in hr', function (string $view) {
    $aleppo = insertHrCity('Aleppo');
    $homs = insertHrCity('Homs', 'حمص');
    Activity::withoutRecording(function () use ($aleppo, $homs): void {
        MealCoverageAssignment::factory()->forCity($aleppo)->create(['employee_id' => insertHrEmployee(firstNameEn: 'Nour', lastNameEn: 'Ali')]);
        MealCoverageAssignment::factory()->forCity($homs)->create(['employee_id' => insertHrEmployee(firstNameEn: 'Rami', lastNameEn: 'Saleh', isActive: false)]);
    });

    Livewire::actingAs(User::factory()->role('Team Member')->create())
        ->test('pages::admin.cities')
        ->set('view', $view)
        ->assertSee(__('cities.meal_officer'))
        ->assertSee('Nour Ali')
        ->assertSee('Rami Saleh')
        ->assertSee(__('cities.responsible_inactive'));
})->with(['grid', 'map']);

test('the project officers section shows each project\'s own officer or the inherited one, and searches projects', function () {
    $aleppo = insertHrCity('Aleppo');
    $own = Project::factory()->create(['city_id' => $aleppo, 'name' => 'Wells Of Hope']);
    Project::factory()->create(['city_id' => $aleppo, 'name' => 'Study Zone']);
    Activity::withoutRecording(function () use ($aleppo, $own): void {
        MealCoverageAssignment::factory()->forCity($aleppo)->create(['employee_id' => insertHrEmployee(firstNameEn: 'Nour', lastNameEn: 'Ali')]);
        MealCoverageAssignment::factory()->forProject($own->id)->create(['employee_id' => insertHrEmployee(firstNameEn: 'Lina', lastNameEn: 'Haddad')]);
    });

    Livewire::actingAs(User::factory()->role('MEAL')->create())
        ->test('pages::admin.cities')
        ->assertSee(__('cities.project_officers'))
        ->assertSee('Lina Haddad')
        ->assertSee(__('cities.inherited_from_governorate'))
        ->set('projectSearch', 'Wells')
        ->assertSee('Wells Of Hope')
        ->assertDontSee('Study Zone');
});

test('the meal manager assigns a governorate officer through the modal', function () {
    $aleppo = insertHrCity('Aleppo');
    $officer = insertHrEmployee(firstNameEn: 'Nour', lastNameEn: 'Ali');

    Livewire::actingAs(mealManager())
        ->test('pages::admin.cities')
        ->set('view', 'grid')
        ->assertSeeHtml('data-test="assign-meal-officer-city-'.$aleppo.'"')
        ->call('openMealModal', 'city', $aleppo)
        ->assertSet('showMealModal', true)
        ->set('mealEmployeeId', (string) $officer)
        ->call('saveMealCoverage')
        ->assertHasNoErrors()
        ->assertSet('showMealModal', false)
        ->assertSee('Nour Ali');

    expect(DB::table('meal_coverages')->where('city_id', $aleppo)->value('employee_id'))->toBe($officer);
});

test('the meal manager gives a project its own officer through the modal', function () {
    $project = Project::factory()->create(['city_id' => insertHrCity('Aleppo'), 'name' => 'Wells Of Hope']);
    $officer = insertHrEmployee(firstNameEn: 'Lina', lastNameEn: 'Haddad');

    Livewire::actingAs(mealManager())
        ->test('pages::admin.cities')
        ->assertSeeHtml('data-test="assign-meal-officer-project-'.$project->id.'"')
        ->call('openMealModal', 'project', $project->id)
        ->set('mealEmployeeId', (string) $officer)
        ->call('saveMealCoverage')
        ->assertHasNoErrors();

    expect(DB::table('meal_coverages')->where('project_id', $project->id)->value('employee_id'))->toBe($officer);
});

test('the modal refuses an employee inactive in hr and warns about a city inactive in hr', function () {
    $tartus = insertHrCity('Tartus', 'طرطوس', isActive: false);

    Livewire::actingAs(mealManager())
        ->test('pages::admin.cities')
        ->call('openMealModal', 'city', $tartus)
        ->assertSee(__('cities.city_inactive_in_hr'))
        ->set('mealEmployeeId', (string) insertHrEmployee(isActive: false))
        ->call('saveMealCoverage')
        ->assertHasErrors(['mealEmployeeId'])
        ->assertSet('showMealModal', true);

    expect(DB::table('meal_coverages')->count())->toBe(0);
});

test('without the meal manager slot the assign controls are hidden and the actions are forbidden', function () {
    $aleppo = insertHrCity('Aleppo');
    $employeeId = insertHrEmployee();
    holdMealManagerSlot();
    $user = userWithRole('MEAL', $employeeId);
    Project::factory()->create(['city_id' => $aleppo]);
    $fresh = fn () => Livewire::actingAs($user)->test('pages::admin.cities')->set('view', 'grid');

    $fresh()->assertDontSeeHtml('data-test="assign-meal-officer-');
    $fresh()->call('openMealModal', 'city', $aleppo)->assertForbidden();
    $fresh()
        ->set('mealScope', 'city')
        ->set('mealTargetId', $aleppo)
        ->set('mealEmployeeId', (string) insertHrEmployee())
        ->call('saveMealCoverage')
        ->assertForbidden();

    expect(DB::table('meal_coverages')->count())->toBe(0);
});

test('meal coverage adds a flat number of queries however many cities and projects have officers', function () {
    $user = User::factory()->role('Team Member')->create();
    $render = function () use ($user): int {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        Livewire::actingAs($user)->test('pages::admin.cities')->set('view', 'grid');

        return $queries;
    };
    $cover = function (): void {
        $cityId = insertHrCity(fake()->unique()->city());
        $project = Project::factory()->create(['city_id' => $cityId]);
        Activity::withoutRecording(function () use ($cityId, $project): void {
            MealCoverageAssignment::factory()->forCity($cityId)->create();
            MealCoverageAssignment::factory()->forProject($project->id)->create();
        });
    };

    $cover();
    $render(); // warms the remembered HR lookups and the settings cache
    $few = $render();

    foreach (range(1, 4) as $ignored) {
        $cover();
    }

    expect($render())->toBe($few);
});
