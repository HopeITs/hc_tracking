<?php

use App\Enums\UserRole;
use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected from the city directory', function () {
    $this->get(route('admin.cities.index'))->assertRedirect(route('login'));
});

test('all authenticated roles can view the city directory', function (UserRole $role) {
    $user = User::factory()->role($role)->create();

    $this->actingAs($user)
        ->get(route('admin.cities.index'))
        ->assertOk()
        ->assertSeeText('Cities')
        ->assertSee(route('admin.cities.index'), false);
})->with([
    'officer' => UserRole::Officer,
    'manager' => UserRole::Manager,
    'meal' => UserRole::Meal,
    'admin' => UserRole::Admin,
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
