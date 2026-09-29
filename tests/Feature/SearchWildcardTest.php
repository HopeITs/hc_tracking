<?php

use App\Models\Donor;
use App\Models\User;
use App\Support\Like;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

test('Like escapes wildcard characters and the escape character', function () {
    expect(Like::escape('50%_off\\'))->toBe('50\\%\\_off\\\\')
        ->and(Like::contains('  UNI  '))->toBe('%UNI%');
});

test('a bare wildcard typed in a search box is matched literally', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = User::factory()->role('Financial')->create();
    Donor::factory()->create(['name' => 'UNICEF', 'country' => null, 'contact_person' => null]);
    Donor::factory()->create(['name' => '100% Aid', 'country' => null, 'contact_person' => null]);

    Livewire::actingAs($user)
        ->test('pages::donors.index')
        ->set('search', '%')
        ->assertSee('100% Aid')
        ->assertDontSee('UNICEF')
        ->set('search', 'UNI_EF')
        ->assertDontSee('UNICEF')
        ->set('search', 'UNI')
        ->assertSee('UNICEF');
});
