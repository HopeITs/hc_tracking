<?php

use App\Models\HrEmployee;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use Carbon\CarbonImmutable;

test('a milestone belongs to a project and a responsible hr employee', function () {
    $project = Project::factory()->create();
    $employeeId = insertHrEmployee();

    $milestone = Milestone::factory()->create([
        'project_id' => $project->id,
        'responsible_user_id' => $employeeId,
    ]);

    expect($milestone->project->id)->toBe($project->id)
        ->and($milestone->responsibleUser)->toBeInstanceOf(HrEmployee::class)
        ->and($milestone->responsibleUser->id)->toBe($employeeId);
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

test('a milestone tree traverses through parent_milestone_id', function () {
    $root = Milestone::factory()->create();
    $child = Milestone::factory()->childOf($root)->create();
    $grandchild = Milestone::factory()->childOf($child)->create();

    expect($grandchild->ancestors()->pluck('id')->all())->toBe([$child->id, $root->id])
        ->and($root->descendants()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$child->id, $grandchild->id])->sort()->values()->all())
        ->and($grandchild->depth())->toBe(2)
        ->and($root->isRoot())->toBeTrue()
        ->and($grandchild->isLeaf())->toBeTrue();
});

test('the roots scope returns only top-level milestones', function () {
    $root = Milestone::factory()->create();
    Milestone::factory()->childOf($root)->create();

    expect(Milestone::roots()->pluck('id')->all())->toBe([$root->id]);
});

test('the childOf factory state puts a milestone in its parent project', function () {
    $root = Milestone::factory()->create();

    $child = Milestone::factory()->childOf($root)->create();

    expect($child->project_id)->toBe($root->project_id)
        ->and($child->parent_milestone_id)->toBe($root->id);
});

test('a milestone returns its children in sequence order', function () {
    $root = Milestone::factory()->create();

    Milestone::factory()->childOf($root)->create(['sequence' => 3, 'name' => 'Third']);
    Milestone::factory()->childOf($root)->create(['sequence' => 1, 'name' => 'First']);
    Milestone::factory()->childOf($root)->create(['sequence' => 2, 'name' => 'Second']);

    expect($root->children->pluck('name')->all())->toBe(['First', 'Second', 'Third']);
});

test('an eager-loaded milestone subtree is ordered by sequence too', function () {
    $root = Milestone::factory()->create();

    Milestone::factory()->childOf($root)->create(['sequence' => 2, 'name' => 'Later']);
    Milestone::factory()->childOf($root)->create(['sequence' => 1, 'name' => 'Earlier']);

    $loaded = Milestone::query()->with('childrenRecursive')->findOrFail($root->id);

    expect($loaded->descendants()->pluck('name')->all())->toBe(['Earlier', 'Later']);
});
