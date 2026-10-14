<?php

use App\Enums\ProjectStatus;
use App\Enums\ProposalPhase;
use App\Enums\ProposalStatus;
use App\Models\Project;
use App\Models\Proposal;

test('a new project is a draft', function () {
    expect(Project::factory()->create()->fresh()->status)->toBe(ProjectStatus::Draft);
});

test('the lifecycle advances one state at a time and ends at archived', function () {
    expect(ProjectStatus::Draft->next())->toBe(ProjectStatus::Approved)
        ->and(ProjectStatus::Approved->next())->toBe(ProjectStatus::Active)
        ->and(ProjectStatus::Active->next())->toBe(ProjectStatus::Closed)
        ->and(ProjectStatus::Closed->next())->toBe(ProjectStatus::Archived)
        ->and(ProjectStatus::Archived->next())->toBeNull();
});

test('only draft, approved and active count as still being delivered', function () {
    expect(ProjectStatus::Draft->isOpen())->toBeTrue()
        ->and(ProjectStatus::Approved->isOpen())->toBeTrue()
        ->and(ProjectStatus::Active->isOpen())->toBeTrue()
        ->and(ProjectStatus::Submitted->isOpen())->toBeFalse()
        ->and(ProjectStatus::Closed->isOpen())->toBeFalse()
        ->and(ProjectStatus::Archived->isOpen())->toBeFalse();
});

test('live() lists every status but the retired submitted', function () {
    expect(ProjectStatus::live())->toBe([
        ProjectStatus::Draft,
        ProjectStatus::Approved,
        ProjectStatus::Active,
        ProjectStatus::Closed,
        ProjectStatus::Archived,
    ])->and(ProjectStatus::cases())->toContain(ProjectStatus::Submitted);
});

test('the open scope returns every project still being delivered', function () {
    Project::factory()->active()->create();
    Project::factory()->approved()->create();
    Project::factory()->create();
    Project::factory()->closed()->create();
    Project::factory()->status(ProjectStatus::Archived)->create();

    expect(Project::query()->open()->count())->toBe(3);
});

test('the phase follows the status and, for a draft, its current proposal', function () {
    expect(Project::factory()->status(ProjectStatus::Archived)->create()->phase())->toBe(ProposalPhase::Archived)
        ->and(Project::factory()->closed()->create()->phase())->toBe(ProposalPhase::Closure)
        ->and(Project::factory()->approved()->create()->phase())->toBe(ProposalPhase::Implementation)
        ->and(Project::factory()->active()->create()->phase())->toBe(ProposalPhase::Monitoring)
        ->and(Project::factory()->create()->phase())->toBe(ProposalPhase::Ideation);

    $draft = Project::factory()->create();
    Proposal::factory()->create(['status' => ProposalStatus::ProposalDraft])->projects()->attach($draft->id);
    Proposal::factory()->create(['status' => ProposalStatus::Rejected])->projects()->attach($draft->id);

    expect($draft->fresh()->phase())->toBe(ProposalPhase::Planning)
        ->and($draft->fresh()->hasActiveProposal())->toBeTrue();
});

test('a draft whose only proposal was rejected has no active proposal', function () {
    $draft = Project::factory()->create();
    Proposal::factory()->create(['status' => ProposalStatus::Rejected])->projects()->attach($draft->id);

    expect($draft->fresh()->hasActiveProposal())->toBeFalse()->and($draft->fresh()->phase())->toBe(ProposalPhase::Ideation);
});

test('a project casts its period to dates', function () {
    $project = Project::factory()->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
    ])->fresh();

    expect($project->start_date->toDateString())->toBe('2026-01-01')
        ->and($project->end_date->toDateString())->toBe('2026-12-31');
});

test('a project sums its demographic targets', function () {
    $project = Project::factory()->create([
        'target_men' => 100,
        'target_women' => 150,
        'target_boys' => 60,
        'target_girls' => 90,
    ]);

    expect($project->targetDemographicTotal())->toBe(400);
});

test('a project with no disaggregation agreed reports null rather than zero', function () {
    $project = Project::factory()->withoutDemographics()->create(['target_beneficiaries' => 5000]);

    // A headline total with no breakdown is a real state. Reporting 0 here
    // would read as "we target nobody", which is a different claim.
    expect($project->targetDemographicTotal())->toBeNull()
        ->and($project->target_beneficiaries)->toBe(5000);
});

test('the headline total and the breakdown are allowed to disagree', function () {
    $project = Project::factory()->create([
        'target_beneficiaries' => 1000,
        'target_men' => 100,
        'target_women' => 100,
        'target_boys' => 0,
        'target_girls' => 0,
    ]);

    // Deliberately permitted: a proposal often commits a total before the
    // disaggregation is agreed. The UI surfaces the gap rather than the schema
    // forbidding it.
    expect($project->target_beneficiaries)->toBe(1000)
        ->and($project->targetDemographicTotal())->toBe(200);
});
