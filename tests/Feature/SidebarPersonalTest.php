<?php

use App\Http\Middleware\RemembersRecentRecord;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

test('a person can pin a sidebar page and unpin it again', function () {
    $user = User::factory()->role('Super Admin')->create();

    $this->actingAs($user)->postJson(route('nav.pins.toggle'), ['route' => 'transfers.index'])
        ->assertOk()
        ->assertJson(['pins' => ['transfers.index']]);

    expect($user->fresh()->nav_pins)->toBe(['transfers.index']);

    $this->actingAs($user)->postJson(route('nav.pins.toggle'), ['route' => 'transfers.index'])
        ->assertOk()
        ->assertJson(['pins' => []]);

    expect($user->fresh()->nav_pins)->toBe([]);
});

test('a page the sidebar does not offer the person cannot be pinned', function () {
    $user = User::factory()->role('Team Member')->create();

    $this->actingAs($user)->postJson(route('nav.pins.toggle'), ['route' => 'admin.users.index'])
        ->assertUnprocessable();

    expect($user->fresh()->nav_pins)->toBeNull();
});

test('pinned pages show in the sidebar', function () {
    $user = User::factory()->role('Super Admin')->create(['nav_pins' => ['transfers.index', 'gone.page']]);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-side-personal', false)
        ->assertSee('transfers.index"', false)
        ->assertDontSee('gone.page', false);
});

test('opening a project counts as a use of it, kept once however often it is opened', function () {
    $user = User::factory()->role('Super Admin')->create();
    $first = Project::factory()->create(['name' => 'Gaza WASH Recovery']);
    $second = Project::factory()->create(['name' => 'Shelter Winterisation']);

    $this->actingAs($user)->get(route('projects.show', $first))->assertOk();
    $this->actingAs($user)->get(route('projects.show', $second))->assertOk();
    $this->actingAs($user)->get(route('projects.show', $first))->assertOk();

    expect(collect(RemembersRecentRecord::mostUsed($user->fresh()->nav_recent))->map(fn (array $entry): array => [$entry['label'], $entry['n']])->all())
        ->toBe([['Gaza WASH Recovery', 2], ['Shelter Winterisation', 1]]);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertSee('data-side-recent', false)
        ->assertSee('Gaza WASH Recovery');
});

test('the most used list puts the record opened most often ahead of a newer one-off', function () {
    $user = User::factory()->role('Super Admin')->create();
    $regular = Project::factory()->create(['name' => 'Opened every day']);
    $glance = Project::factory()->create(['name' => 'Looked at once']);

    foreach (range(1, 3) as $time) {
        $this->actingAs($user)->get(route('projects.show', $regular))->assertOk();
    }
    $this->actingAs($user)->get(route('projects.show', $glance))->assertOk();

    expect(array_column(RemembersRecentRecord::mostUsed($user->fresh()->nav_recent), 'label'))->toBe(['Opened every day', 'Looked at once']);
});

test('uses fade with time, so a record opened today outranks one used often long ago', function () {
    $user = User::factory()->role('Super Admin')->create();
    $old = Project::factory()->create(['name' => 'Used a lot last quarter']);
    $today = Project::factory()->create(['name' => 'Opened today']);

    $this->travelTo(now()->subDays(60));
    foreach (range(1, 3) as $time) {
        $this->actingAs($user)->get(route('projects.show', $old))->assertOk();
    }
    $this->travelBack();
    $this->actingAs($user)->get(route('projects.show', $today))->assertOk();

    expect(array_column(RemembersRecentRecord::mostUsed($user->fresh()->nav_recent), 'label'))->toBe(['Opened today', 'Used a lot last quarter']);
});

test('the sidebar shows only the few most used records but keeps counting the rest', function () {
    $user = User::factory()->role('Super Admin')->create();

    Project::factory()->count(RemembersRecentRecord::Limit + 2)->create()
        ->each(fn (Project $project) => $this->actingAs($user)->get(route('projects.show', $project))->assertOk());

    expect($user->fresh()->nav_recent)->toHaveCount(RemembersRecentRecord::Limit + 2)
        ->and(RemembersRecentRecord::mostUsed($user->fresh()->nav_recent))->toHaveCount(RemembersRecentRecord::Limit);
});

test('a list saved before uses were counted still reads, newest first', function () {
    $stored = [
        ['route' => 'projects.show', 'key' => 'b', 'label' => 'Newer', 'kind' => 'project'],
        ['route' => 'projects.show', 'key' => 'a', 'label' => 'Older', 'kind' => 'project'],
        'broken',
    ];

    expect(array_column(RemembersRecentRecord::mostUsed($stored), 'label'))->toBe(['Newer', 'Older']);
});

test('inside a project the sidebar leads with that project\'s sections', function () {
    $project = Project::factory()->create();

    $this->actingAs(User::factory()->role('Super Admin')->create())
        ->get(route('projects.show', [$project, 'tab' => 'tasks']))
        ->assertOk()
        ->assertSee('data-side-context="'.$project->getRouteKey().'"', false)
        ->assertSee('data-side-section="tasks"', false)
        ->assertSee('data-side-context-back', false);

    $this->actingAs(User::factory()->role('Super Admin')->create())
        ->get(route('projects.index'))
        ->assertDontSee('data-side-context=', false);
});

test('a group glows when it holds something of the viewer\'s due within days', function () {
    $user = User::factory()->role('Super Admin')->create();
    Task::factory()->create(['created_by' => $user->id, 'due_date' => today()->addDays(2), 'department_id' => null]);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSeeInOrder(['data-side-group-toggle="workspace"', 'data-side-due'], false);

    $this->actingAs(User::factory()->role('Super Admin')->create())->get(route('dashboard'))
        ->assertDontSee('data-side-due', false);
});
