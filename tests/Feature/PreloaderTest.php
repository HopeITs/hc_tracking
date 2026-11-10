<?php

use App\Models\User;

test('the login screen shows no loading splash', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertDontSee('data-preloader', false);
});

test('application pages show no loading splash', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('data-preloader', false);
});

test('page switches show livewire\'s thin brand progress bar instead of a splash', function () {
    expect(config('livewire.navigate.show_progress_bar'))->toBeTrue()
        ->and(config('livewire.navigate.progress_bar_color'))->toBe('#be1f24');
});
