<?php

use App\Enums\MilestoneStatus;
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

    Task::factory(2)->create(['source_type' => 'milestone', 'source_id' => $milestone->id]);
    Task::factory()->create();

    expect($milestone->tasks)->toHaveCount(2);
});

test('a milestone casts its dates, sequence and weight', function () {
    $milestone = Milestone::factory()->create([
        'planned_start_date' => '2026-03-31',
        'planned_end_date' => '2026-04-30',
        'sequence' => 3,
        'weight_percentage' => '25.5',
    ])->fresh();

    expect($milestone->planned_start_date)->toBeInstanceOf(CarbonImmutable::class)
        ->and($milestone->planned_start_date->toDateString())->toBe('2026-03-31')
        ->and($milestone->planned_end_date->toDateString())->toBe('2026-04-30')
        ->and($milestone->actual_start_date)->toBeNull()
        ->and($milestone->actual_end_date)->toBeNull()
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

test('a milestone left without an explicit sequence is placed after its existing siblings', function () {
    $project = Project::factory()->create();

    $first = Milestone::factory()->create(['project_id' => $project->id]);
    $second = Milestone::factory()->create(['project_id' => $project->id]);

    $child = Milestone::factory()->childOf($first)->create();
    $grandchild = Milestone::factory()->childOf($child)->create();

    expect($first->sequence)->toBe(1)
        ->and($second->sequence)->toBe(2)
        ->and($child->sequence)->toBe(1)
        ->and($grandchild->sequence)->toBe(1);
});

test('re-nesting a milestone without an explicit sequence appends it after its new siblings', function () {
    $root = Milestone::factory()->create();
    Milestone::factory()->childOf($root)->create(['sequence' => 1]);
    Milestone::factory()->childOf($root)->create(['sequence' => 2]);

    $mover = Milestone::factory()->create(['project_id' => $root->project_id, 'sequence' => 9]);
    $mover->parent_milestone_id = $root->id;
    $mover->save();

    expect($mover->fresh()->sequence)->toBe(3);
});

test('moving a milestone to ongoing stamps actual_start_date once and never again', function () {
    $milestone = Milestone::factory()->create(['status' => MilestoneStatus::Planned]);

    $this->travelTo('2026-05-10');
    $milestone->status = MilestoneStatus::Ongoing;
    $milestone->save();

    expect($milestone->fresh()->actual_start_date->toDateString())->toBe('2026-05-10');

    $this->travelTo('2026-05-20');
    $milestone->name = 'Renamed';
    $milestone->save();

    expect($milestone->fresh()->actual_start_date->toDateString())->toBe('2026-05-10');
});

test('moving a milestone straight to reached stamps both actual dates', function () {
    $milestone = Milestone::factory()->create(['status' => MilestoneStatus::Planned]);

    $this->travelTo('2026-06-01');
    $milestone->status = MilestoneStatus::Reached;
    $milestone->save();

    expect($milestone->fresh()->actual_start_date->toDateString())->toBe('2026-06-01')
        ->and($milestone->fresh()->actual_end_date->toDateString())->toBe('2026-06-01');
});

test('reaching a milestone that was already ongoing keeps its original actual_start_date', function () {
    $milestone = Milestone::factory()->create(['status' => MilestoneStatus::Planned]);

    $this->travelTo('2026-05-01');
    $milestone->status = MilestoneStatus::Ongoing;
    $milestone->save();

    $this->travelTo('2026-06-15');
    $milestone->status = MilestoneStatus::Reached;
    $milestone->save();

    expect($milestone->fresh()->actual_start_date->toDateString())->toBe('2026-05-01')
        ->and($milestone->fresh()->actual_end_date->toDateString())->toBe('2026-06-15');
});

test('an explicitly supplied actual date is never overwritten by the status stamp', function () {
    $milestone = Milestone::factory()->create(['status' => MilestoneStatus::Planned]);

    $milestone->status = MilestoneStatus::Reached;
    $milestone->actual_end_date = '2026-01-01';
    $milestone->save();

    expect($milestone->fresh()->actual_end_date->toDateString())->toBe('2026-01-01');
});

test('a milestone is missed only while still planned past its planned end date', function () {
    $this->travelTo('2026-06-10');

    $stillPlanned = Milestone::factory()->create(['status' => MilestoneStatus::Planned, 'planned_end_date' => '2026-06-01']);
    $notDueYet = Milestone::factory()->create(['status' => MilestoneStatus::Planned, 'planned_end_date' => '2026-07-01']);
    $ongoingButLate = Milestone::factory()->create(['status' => MilestoneStatus::Ongoing, 'planned_end_date' => '2026-06-01']);
    $noDeadline = Milestone::factory()->create(['status' => MilestoneStatus::Planned, 'planned_end_date' => null]);

    expect($stillPlanned->isMissed())->toBeTrue()
        ->and($notDueYet->isMissed())->toBeFalse()
        ->and($ongoingButLate->isMissed())->toBeFalse()
        ->and($noDeadline->isMissed())->toBeFalse();
});

test('a milestone is overdue only when it finished after its planned end date', function () {
    $finishedLate = Milestone::factory()->create(['planned_end_date' => '2026-06-01', 'actual_end_date' => '2026-06-05']);
    $finishedOnTime = Milestone::factory()->create(['planned_end_date' => '2026-06-01', 'actual_end_date' => '2026-06-01']);
    $notFinishedYet = Milestone::factory()->create(['planned_end_date' => '2026-06-01', 'actual_end_date' => null]);

    expect($finishedLate->isOverdue())->toBeTrue()
        ->and($finishedOnTime->isOverdue())->toBeFalse()
        ->and($notFinishedYet->isOverdue())->toBeFalse();
});
