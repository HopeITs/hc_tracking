<?php

use App\Models\Milestone;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\User;
use Livewire\Livewire;

test('a funded project reports how many of its milestones are reached', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create();
    $project = Project::factory()->create(['name' => 'Latrine Block Rehabilitation']);

    Milestone::factory()->count(2)->reached()->create(['project_id' => $project->id]);
    Milestone::factory()->count(3)->create(['project_id' => $project->id]);

    $proposal->projects()->attach($project);

    Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->set('tab', 'projects')
        ->assertSee('Latrine Block Rehabilitation')
        // Two of the five milestones are reached; the other three are not.
        ->assertSee('2 / 5');
});

test('a project running past the grant term is flagged', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create([
        'planned_date_begin' => '2026-01-01',
        'planned_date_end' => '2026-12-31',
    ]);

    $proposal->projects()->attach(
        Project::factory()->create([
            'name' => 'Overrunning Water Network',
            'start_date' => '2026-02-01',
            'end_date' => '2027-06-30',
        ])
    );

    Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->set('tab', 'projects')
        ->assertSee('Runs past the end of this grant')
        ->assertSeeHtml('data-outruns-grant');
});

test('a project ending inside the grant term is not flagged', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create([
        'planned_date_begin' => '2026-01-01',
        'planned_date_end' => '2026-12-31',
    ]);

    $proposal->projects()->attach(
        Project::factory()->create([
            'name' => 'Contained Mobile Clinic',
            'start_date' => '2026-02-01',
            'end_date' => '2026-11-30',
        ])
    );

    Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->set('tab', 'projects')
        ->assertSee('Contained Mobile Clinic')
        ->assertDontSeeHtml('data-outruns-grant');
});

test('counting reached milestones does not query once per project', function () {
    $user = User::factory()->create();
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $countFor = function (int $projects) use ($user, &$queries): int {
        $proposal = Proposal::factory()->create();

        foreach (range(1, $projects) as $index) {
            $project = Project::factory()->create();
            Milestone::factory()->count(2)->reached()->create(['project_id' => $project->id]);
            $proposal->projects()->attach($project);
        }

        $queries = 0;

        Livewire::actingAs($user)
            ->test('pages::proposals.show', ['proposal' => $proposal])
            ->set('tab', 'projects');

        return $queries;
    };

    $countFor(1); // warm per-request caches (permissions, settings)

    // The reached count rides on the projects query as a subquery. Four
    // projects must not cost a single statement more than one.
    expect($countFor(4))->toBe($countFor(1));
});
