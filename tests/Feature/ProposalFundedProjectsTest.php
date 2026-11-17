<?php

use App\Models\Project;
use App\Models\Proposal;
use App\Models\User;
use Livewire\Livewire;

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
