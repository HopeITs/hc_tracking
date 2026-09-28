<?php

use App\Models\User;
use Livewire\Exceptions\ComponentNotFoundException;
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

/**
 * Accounts belong to HR and administrators (Admin > Users keeps its own
 * delete), so the profile no longer lets people delete themselves.
 */
test('the profile page does not offer self-service account deletion', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('profile.edit'))
        ->assertOk()
        ->assertDontSee('delete-user-button', false)
        ->assertDontSee(__('settings.delete_account'));
});

test('the self-service account deletion components no longer exist', function (string $component) {
    $this->actingAs(User::factory()->create());

    expect(fn () => Livewire::test($component))->toThrow(ComponentNotFoundException::class);
})->with([
    'pages::settings.delete-user-form',
    'pages::settings.delete-user-modal',
]);
