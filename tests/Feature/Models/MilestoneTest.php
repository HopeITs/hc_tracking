<?php

use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;

test('a milestone belongs to a project and a responsible user', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();

    $milestone = Milestone::factory()->create([
        'project_id' => $project->id,
        'responsible_user_id' => $user->id,
    ]);

    expect($milestone->project->id)->toBe($project->id)
        ->and($milestone->responsibleUser)->toBeInstanceOf(User::class)
        ->and($milestone->responsibleUser->id)->toBe($user->id);
});

test('a milestone nests under a parent milestone', function () {
    $parent = Milestone::factory()->create();
    $child = Milestone::factory()->create([
        'project_id' => $parent->project_id,
        'parent_milestone_id' => $parent->id,
    ]);

    expect($child->parent->id)->toBe($parent->id)
        ->and($parent->children)->toHaveCount(1)
        ->and($parent->children->first()->id)->toBe($child->id)
        ->and($parent->parent)->toBeNull();
});

test('a milestone has the tasks that deliver it', function () {
    $milestone = Milestone::factory()->create();

    Task::factory(2)->create(['milestone_id' => $milestone->id]);
    Task::factory()->create();

    expect($milestone->tasks)->toHaveCount(2);
});

test('a milestone casts its dates, sequence and weight', function () {
    $milestone = Milestone::factory()->create([
        'planned_date' => '2026-03-31',
        'actual_date' => null,
        'sequence' => 3,
        'weight_percentage' => '25.5',
    ])->fresh();

    expect($milestone->planned_date)->toBeInstanceOf(CarbonImmutable::class)
        ->and($milestone->planned_date->toDateString())->toBe('2026-03-31')
        ->and($milestone->actual_date)->toBeNull()
        ->and($milestone->sequence)->toBe(3)
        ->and($milestone->weight_percentage)->toBe('25.50');
});
