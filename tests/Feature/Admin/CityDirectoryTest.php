<?php

use App\Models\Project;
use App\Models\User;
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
    'officer' => 'Officer',
    'manager' => 'Managers',
    'meal' => 'MEAL',
    'admin' => 'Super Admin',
]);

test('the directory lists every hr city in english and arabic, active or not', function () {
    insertHrCity('Damascus', 'دمشق');
    insertHrCity('Tartus', 'طرطوس', isActive: false);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::admin.cities')
        ->assertSee('Damascus')
        ->assertSee('دمشق')
        ->assertSee('Tartus')
        ->assertSee('Inactive');
});

test('the directory searches english and arabic names and clears the search', function () {
    insertHrCity('Damascus', 'دمشق');
    insertHrCity('Aleppo', 'حلب');

    Livewire::actingAs(User::factory()->create())
        ->test('pages::admin.cities')
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

    Livewire::actingAs(User::factory()->create())
        ->test('pages::admin.cities')
        ->assertSee('2 projects')
        ->assertSee('0 projects');
});

test('city names are escaped in the directory', function () {
    $unsafeName = '<script>alert("city")</script>';
    insertHrCity($unsafeName);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::admin.cities')
        ->assertSee($unsafeName)
        ->assertDontSee($unsafeName, false);
});

test('the directory offers no way to change hr cities', function () {
    Livewire::actingAs(User::factory()->create())
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

    $component = Livewire::actingAs(User::factory()->create())
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

    Livewire::actingAs(User::factory()->create())
        ->test('pages::admin.cities')
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

    $component = Livewire::actingAs(User::factory()->create())
        ->test('pages::admin.cities')
        ->set('sort', 'projects');

    $names = $component->instance()->cities()->pluck('name')->all();

    expect($names)->toBe(['Aleppo', 'Homs']);
});

test('a city links straight to its filtered project list', function () {
    $aleppo = insertHrCity('Aleppo');

    Livewire::actingAs(User::factory()->create())
        ->test('pages::admin.cities')
        ->assertSee(route('projects.index', ['city' => $aleppo]), false);
});

test('the map view plots every city with a real link, even one the search would hide in the grid', function () {
    insertHrCity('Aleppo');
    $homs = insertHrCity('Homs');

    Livewire::actingAs(User::factory()->create())
        ->test('pages::admin.cities')
        ->set('view', 'map')
        ->set('search', 'Homs')
        ->assertSee('Aleppo') // dimmed on the map, but never removed from it
        ->assertSee('Homs')
        ->assertSee(route('projects.index', ['city' => $homs]), false);
});

test('a city with no plotted position falls into the "not shown on map" tray', function () {
    insertHrCity('Neverland');

    Livewire::actingAs(User::factory()->create())
        ->test('pages::admin.cities')
        ->set('view', 'map')
        ->assertSee('Neverland')
        ->assertSee(__('cities.not_shown_on_map'));
});
