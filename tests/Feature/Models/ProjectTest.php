<?php

use App\Models\City;
use App\Models\Milestone;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Risk;
use App\Models\User;

test('a project belongs to a city, a program and a user', function () {
    $city = City::factory()->create();
    $program = Program::factory()->create();
    $user = User::factory()->create();

    $project = Project::factory()->create([
        'city_id' => $city->id,
        'program_id' => $program->id,
        'user_id' => $user->id,
    ]);

    expect($project->city->id)->toBe($city->id)
        ->and($project->program->id)->toBe($program->id)
        ->and($project->user)->toBeInstanceOf(User::class)
        ->and($project->user->id)->toBe($user->id);
});

test('a project attaches to proposals through the project_proposal pivot', function () {
    $project = Project::factory()->create();
    $proposal = Proposal::factory()->create();

    $project->proposals()->attach($proposal);

    expect($project->proposals()->sole()->id)->toBe($proposal->id)
        ->and($proposal->projects()->sole()->id)->toBe($project->id);

    $this->assertDatabaseHas('project_proposal', [
        'project_id' => $project->id,
        'proposal_id' => $proposal->id,
    ]);
});

test('a project keeps timestamps on its proposal links', function () {
    $project = Project::factory()->create();

    $project->proposals()->attach(Proposal::factory()->create());

    expect($project->proposals()->sole()->pivot->created_at)->not->toBeNull();
});

test('a project has its risks, milestones and targets', function () {
    $project = Project::factory()->create();

    Risk::factory(2)->create(['project_id' => $project->id]);
    Milestone::factory()->create(['project_id' => $project->id]);
    PlanIndicator::factory()->create(['project_id' => $project->id]);

    expect($project->risks)->toHaveCount(2)
        ->and($project->milestones)->toHaveCount(1)
        ->and($project->planIndicators)->toHaveCount(1);
});
