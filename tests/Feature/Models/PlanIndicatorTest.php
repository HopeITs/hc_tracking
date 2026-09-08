<?php

use App\Models\Indicator;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\YearlyPlan;

test('a target planned in a yearly plan resolves that plan as its source', function () {
    $plan = YearlyPlan::factory()->create();
    $target = PlanIndicator::factory()->forYearlyPlan($plan)->create();

    expect($target->source)->toBeInstanceOf(YearlyPlan::class)
        ->and($target->source->id)->toBe($plan->id);
});

test('a target planned in a proposal resolves that proposal as its source', function () {
    $proposal = Proposal::factory()->create();
    $target = PlanIndicator::factory()->forProposal($proposal)->create();

    expect($target->source)->toBeInstanceOf(Proposal::class)
        ->and($target->source->id)->toBe($proposal->id);
});

test('a target stores the short morph key rather than the class name', function () {
    $target = PlanIndicator::factory()->forYearlyPlan(YearlyPlan::factory()->create())->create();

    $this->assertDatabaseHas('plan_indicators', [
        'id' => $target->id,
        'source_type' => 'yearly_plan',
    ]);

    expect(PlanIndicator::factory()->forProposal()->create()->source_type)->toBe('proposal');
});

test('a target belongs to an indicator and optionally a project', function () {
    $indicator = Indicator::factory()->create();
    $project = Project::factory()->create();

    $target = PlanIndicator::factory()->create([
        'indicator_id' => $indicator->id,
        'project_id' => $project->id,
    ]);

    expect($target->indicator->id)->toBe($indicator->id)
        ->and($target->project->id)->toBe($project->id)
        ->and(PlanIndicator::factory()->create()->project)->toBeNull();
});

test('a target has the actuals reported against it', function () {
    $target = PlanIndicator::factory()->create();

    Report::factory(2)->create(['plan_indicator_id' => $target->id]);
    Report::factory()->create();

    expect($target->reports)->toHaveCount(2);
});

test('a target casts its value to two decimal places', function () {
    expect(PlanIndicator::factory()->create(['target' => '12000.5'])->fresh()->target)->toBe('12000.50');
});
