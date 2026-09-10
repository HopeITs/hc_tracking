<?php

use App\Enums\UserRole;
use App\Models\City;
use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected from the city directory', function () {
    $this->get(route('admin.cities.index'))->assertRedirect(route('login'));
});

test('all authenticated roles can access city management while permissions are deferred', function (UserRole $role) {
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

test('authenticated users can search and clear the city directory', function () {
    $user = User::factory()->create();
    City::factory()->create(['name' => 'Damascus']);
    City::factory()->create(['name' => 'Aleppo']);

    Livewire::actingAs($user)
        ->test('pages::admin.cities')
        ->assertSee('Damascus')
        ->assertSee('Aleppo')
        ->set('search', 'Damas')
        ->assertSee('Damascus')
        ->assertDontSee('Aleppo')
        ->call('clearSearch')
        ->assertSet('search', '')
        ->assertSee('Aleppo');
});

test('city names are escaped in the directory', function () {
    $user = User::factory()->create();
    $unsafeName = '<script>alert("city")</script>';
    City::factory()->create(['name' => $unsafeName]);

    Livewire::actingAs($user)
        ->test('pages::admin.cities')
        ->assertSee($unsafeName)
        ->assertDontSee($unsafeName, false);
});

test('authenticated users can create a trimmed city record', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::admin.cities')
        ->call('openCreateModal')
        ->set('name', '  Homs  ')
        ->call('saveCity')
        ->assertHasNoErrors()
        ->assertSet('showCityModal', false);

    $city = City::query()->where('name', 'Homs')->sole();

    expect($city->name)->toBe('Homs');
});

test('city names must be present and no longer than 100 characters', function (string $name) {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::admin.cities')
        ->call('openCreateModal')
        ->set('name', $name)
        ->call('saveCity')
        ->assertHasErrors(['name']);

    expect(City::query()->count())->toBe(0);
})->with([
    'empty' => '',
    'too long' => str_repeat('A', 101),
]);

test('city names are unique regardless of letter case', function () {
    $user = User::factory()->create();
    City::factory()->create(['name' => 'Latakia']);

    Livewire::actingAs($user)
        ->test('pages::admin.cities')
        ->call('openCreateModal')
        ->set('name', 'latakia')
        ->call('saveCity')
        ->assertHasErrors(['name' => 'unique'])
        ->assertSee('A city with this name already exists.');

    expect(City::query()->count())->toBe(1);
});

test('authenticated users can update a city with the same or a new unique name', function () {
    $user = User::factory()->create();
    $city = City::factory()->create(['name' => 'Tartus']);

    Livewire::actingAs($user)
        ->test('pages::admin.cities')
        ->call('openEditModal', $city->id)
        ->assertSet('name', 'Tartus')
        ->call('saveCity')
        ->assertHasNoErrors()
        ->call('openEditModal', $city->id)
        ->set('name', 'Tartous')
        ->call('saveCity')
        ->assertHasNoErrors();

    expect($city->refresh()->name)->toBe('Tartous');
});

test('a city cannot be renamed to another existing city name', function () {
    $user = User::factory()->create();
    $city = City::factory()->create(['name' => 'Idlib']);
    City::factory()->create(['name' => 'Daraa']);

    Livewire::actingAs($user)
        ->test('pages::admin.cities')
        ->call('openEditModal', $city->id)
        ->set('name', 'Daraa')
        ->call('saveCity')
        ->assertHasErrors(['name' => 'unique']);

    expect($city->refresh()->name)->toBe('Idlib');
});

test('authenticated users can delete an unreferenced city', function () {
    $user = User::factory()->create();
    $city = City::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::admin.cities')
        ->call('confirmCityDeletion', $city->id)
        ->assertSet('deleteError', null)
        ->call('deleteCity')
        ->assertSet('showDeleteModal', false);

    $this->assertModelMissing($city);
});

test('referenced cities remain and explain why deletion is unavailable', function () {
    $user = User::factory()->create();
    $city = City::factory()->create();
    Project::factory()->for($city)->create();

    Livewire::actingAs($user)
        ->test('pages::admin.cities')
        ->call('confirmCityDeletion', $city->id)
        ->assertSet('showDeleteModal', true)
        ->assertSee('This city is assigned to 1 project and cannot be deleted.')
        ->call('deleteCity')
        ->assertSet('showDeleteModal', true);

    $this->assertModelExists($city);
});
