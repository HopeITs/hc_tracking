<?php

use App\Models\Task;
use App\Models\User;
use App\Support\Desk\Destinations;
use App\Support\Desk\Panels;
use Livewire\Livewire;

test('a page starts from its own default set, minus panels the viewer may not see', function () {
    $panels = app(Panels::class);

    expect(array_column($panels->pageLayoutFor(User::factory()->role('Super Admin')->create(), 'projects.show'), 'panel'))->toBe(['tasks', 'coming'])
        ->and(array_column($panels->pageLayoutFor(User::factory()->role('Team Member')->create(), 'transfers.index'), 'panel'))->not->toContain('transfers', 'money');
});

test('adding, removing and resetting panels is saved per page', function () {
    $user = User::factory()->role('Super Admin')->create();

    Livewire::actingAs($user)->test('desk.page-panels', ['routeName' => 'projects.index'])
        ->call('load')
        ->call('addPanel', 'needs')
        ->call('removePanel', 'risks');

    expect(array_column(app(Panels::class)->pageLayoutFor($user->refresh(), 'projects.index'), 'panel'))->toBe(['tasks', 'coming', 'needs'])
        ->and(array_column(app(Panels::class)->pageLayoutFor($user, 'tasks.index'), 'panel'))->toBe(['tasks', 'coming', 'waiting', 'deliverables']);

    Livewire::actingAs($user)->test('desk.page-panels', ['routeName' => 'projects.index'])->call('resetLayout');

    expect($user->refresh()->page_panels)->toBeNull();
});

test('a panel the viewer may not see cannot be added, and an unknown route is never stored', function () {
    $user = User::factory()->role('Team Member')->create();

    Livewire::actingAs($user)->test('desk.page-panels', ['routeName' => 'projects.index'])->call('addPanel', 'money');
    app(Panels::class)->savePageLayout($user, 'no.such.route', [['panel' => 'needs', 'size' => 'L']]);

    expect($user->refresh()->page_panels)->toBeNull();
});

test('a Super Admin may place every panel and open every page, role-tied ones included', function () {
    $admin = User::factory()->role('Super Admin')->create();

    expect(app(Panels::class)->lockedFor($admin))->toBe([])
        ->and(array_keys(app(Panels::class)->availableTo($admin)))->toContain('validate', 'myteam', 'reviews', 'templates')
        ->and(array_column(app(Destinations::class)->for($admin), 'route'))->toContain('reviews.index');
});

test('a panel pages its rows, fits the page to the card when measured, and its filter popup waits for Done', function () {
    $admin = User::factory()->role('Super Admin')->create();
    Task::factory()->count(8)->create(['created_by' => $admin->id]);

    $panel = Livewire::actingAs($admin)
        ->test('desk.panels.tasks', ['size' => 'L', 'index' => 0, 'total' => 1]);

    $panel->assertSee(__('desk.range_of', ['from' => 1, 'to' => 6, 'total' => 8]))
        ->assertSeeHtml('data-fit data-fit-per="6" data-fit-total="8"')
        ->assertDontSeeHtml('data-directory-search')
        ->assertDontSeeHtml('wire:model.live')
        ->assertSeeHtml('x-on:click="applyFilters()"');

    $panel->call('fitRows', 3)
        ->assertSet('perPage', 3)
        ->assertSee(__('desk.range_of', ['from' => 1, 'to' => 3, 'total' => 8]))
        ->call('nextPage')->assertSet('page', 1)
        ->assertSee(__('desk.range_of', ['from' => 4, 'to' => 6, 'total' => 8]))
        ->call('fitRows', 999)->assertSet('perPage', 24)
        ->call('applyFilters')->assertSet('page', 0);
});

test('Done writes only facets and the panel\'s own filter properties', function () {
    $admin = User::factory()->role('Super Admin')->create();

    Livewire::actingAs($admin)
        ->test('desk.panels.tasks', ['size' => 'L', 'index' => 0, 'total' => 1])
        ->call('applyFilters', ['scope' => 'department', 'facets.project' => 'Alpha', 'page' => 5, 'perPage' => 2, 'size' => 'XL'])
        ->assertSet('scope', 'department')
        ->assertSet('facets', ['project' => 'Alpha'])
        ->assertSet('page', 0)
        ->assertSet('perPage', 0)
        ->assertSet('size', 'L');
});

test('a panel with nothing to filter has no filter button', function () {
    Livewire::actingAs(User::factory()->role('Super Admin')->create())
        ->test('desk.panels.reviews', ['size' => 'L', 'index' => 0, 'total' => 1])
        ->assertDontSeeHtml('data-panel-filter');
});
