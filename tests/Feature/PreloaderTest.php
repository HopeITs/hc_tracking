<?php

use App\Models\User;

test('the login screen renders the logo preloader', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('x-persist="preloader"', false)
        ->assertSee('data-preloader', false)
        ->assertSee(__('common.loading'));
});

test('application pages render one persisted preloader that starts in the boot state', function () {
    $this->actingAs(User::factory()->create());

    $content = $this->get(route('dashboard'))->assertOk()->getContent();

    expect(substr_count($content, 'x-persist="preloader"'))->toBe(1)
        ->and($content)->toContain('data-state="boot"')
        ->and($content)->toContain(__('common.loading'));
});

test('error pages stay free of the preloader', function () {
    config(['app.debug' => false]);

    $this->get('/this-route-does-not-exist')
        ->assertNotFound()
        ->assertDontSee('data-preloader', false);
});

test('livewire progress bar is off so the preloader is the only navigation indicator', function () {
    expect(config('livewire.navigate.show_progress_bar'))->toBeFalse();
});

test('the handwriting intro is chosen on every full load with no replay window', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee("document.documentElement.dataset.preloader = 'intro'", false)
        ->assertDontSee('hc-preloader:intro-at', false);
});
