<?php

use App\Models\User;
use Livewire\Livewire;

test('profile page is displayed', function () {
    $this->actingAs($user = User::factory()->create());

    $this->get(route('profile.edit'))->assertOk();
});

test('the profile shows the name and email from hr without letting them change', function () {
    $user = User::factory()->create(['name' => 'Rana Darwish', 'email' => 'rana@example.org']);

    Livewire::actingAs($user)
        ->test('pages::settings.profile')
        ->assertSet('name', 'Rana Darwish')
        ->assertSet('email', 'rana@example.org')
        ->assertSee('To change your name or email, update your record in the HR system.')
        ->assertDontSee('update-profile-button', false);

    expect(fn () => Livewire::actingAs($user)->test('pages::settings.profile')->call('updateProfileInformation'))
        ->toThrow(Exception::class);
});

test('user can delete their account with their hr password', function () {
    $user = User::factory()->withHrAccount()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'password')
        ->call('deleteUser');

    $response
        ->assertHasNoErrors()
        ->assertRedirect('/');

    expect($user->fresh())->toBeNull();
    expect(auth()->check())->toBeFalse();
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->withHrAccount()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'wrong-password')
        ->call('deleteUser');

    $response->assertHasErrors(['password']);

    expect($user->fresh())->not->toBeNull();
});
