<?php

use App\Enums\MilestoneStatus;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\Desk\Destinations;
use App\Support\Desk\Panels;
use Livewire\Livewire;

test('a page starts from its own default set, minus panels the viewer may not see', function () {
    $panels = app(Panels::class);

    expect(array_column($panels->pageLayoutFor(User::factory()->role('Super Admin')->create(), 'projects.show'), 'panel'))->toBe(['milestones', 'tasks', 'coming'])
        ->and(array_column($panels->pageLayoutFor(User::factory()->role('Team Member')->create(), 'transfers.index'), 'panel'))->not->toContain('transfers', 'money');
});

test('adding, removing and resetting panels is saved per page', function () {
    $user = User::factory()->role('Super Admin')->create();

    Livewire::actingAs($user)->test('desk.page-panels', ['routeName' => 'projects.index'])
        ->call('load')
        ->call('addPanel', 'needs')
        ->call('removePanel', 'risks');

    expect(array_column(app(Panels::class)->pageLayoutFor($user->refresh(), 'projects.index'), 'panel'))->toBe(['milestones', 'tasks', 'coming', 'needs'])
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

test('a panel offers dropdown filters read from its own rows and applies them', function () {
    $alpha = Project::factory()->create(['name' => 'Alpha project']);
    $beta = Project::factory()->create(['name' => 'Beta project']);
    Milestone::factory()->create(['project_id' => $alpha->id, 'name' => 'Alpha step', 'status' => MilestoneStatus::Planned, 'planned_end_date' => now()->addDays(3)]);
    Milestone::factory()->create(['project_id' => $beta->id, 'name' => 'Beta step', 'status' => MilestoneStatus::Planned, 'planned_end_date' => now()->addDays(4)]);

    Livewire::actingAs(User::factory()->role('Super Admin')->create())
        ->test('desk.panels.milestones', ['size' => 'L', 'index' => 0, 'total' => 1])
        ->assertSee('Alpha step')->assertSee('Beta step')
        ->set('facets.project', 'Alpha project')
        ->assertSee('Alpha step')->assertDontSee('Beta step')
        ->call('clearFilters')
        ->assertSee('Beta step');
});
