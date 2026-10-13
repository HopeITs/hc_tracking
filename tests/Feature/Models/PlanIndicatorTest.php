<?php

use App\Enums\IndicatorMeasure;
use App\Models\AnnualPlan;
use App\Models\Indicator;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Result;

test('a target planned in a annual plan resolves that plan as its source', function () {
    $plan = AnnualPlan::factory()->create();
    $target = PlanIndicator::factory()->forAnnualPlan($plan)->create();

    expect($target->source)->toBeInstanceOf(AnnualPlan::class)
        ->and($target->source->id)->toBe($plan->id);
});

test('a target planned in a proposal resolves that proposal as its source', function () {
    $proposal = Proposal::factory()->create();
    $target = PlanIndicator::factory()->forProposal($proposal)->create();

    expect($target->source)->toBeInstanceOf(Proposal::class)
        ->and($target->source->id)->toBe($proposal->id);
});

test('a target stores the short morph key rather than the class name', function () {
    $target = PlanIndicator::factory()->forAnnualPlan(AnnualPlan::factory()->create())->create();

    $this->assertDatabaseHas('plan_indicators', [
        'id' => $target->id,
        'source_type' => 'annual_plan',
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

test('a numeric target counts only its approved reports, summed', function () {
    $target = PlanIndicator::factory()->create(['target' => 1000]);
    Report::factory()->approved()->for($target)->create(['value' => 300]);
    Report::factory()->approved()->for($target)->create(['value' => 200]);
    Report::factory()->reviewed()->for($target)->create(['value' => 150]);
    Report::factory()->for($target)->create(['value' => 50]);

    expect($target->achievedValue())->toBe(500.0)
        ->and($target->pendingValue())->toBe(200.0)
        ->and($target->percentAchieved())->toBe(50.0)
        ->and($target->achievementStatus())->toBe('in_progress');
});

test('a percentage target takes the latest approved level rather than a sum', function () {
    $indicator = Indicator::factory()->create(['measure' => IndicatorMeasure::Percentage]);
    $target = PlanIndicator::factory()->for($indicator)->create(['target' => 60]);
    Report::factory()->approved()->for($target)->create(['value' => 40, 'planned_date' => '2026-03-31']);
    Report::factory()->approved()->for($target)->create(['value' => 55, 'planned_date' => '2026-06-30']);
    Report::factory()->reviewed()->for($target)->create(['value' => 70, 'planned_date' => '2026-09-30']);

    expect($target->achievedValue())->toBe(55.0)
        ->and($target->pendingValue())->toBe(0.0)
        ->and($target->achievementStatus())->toBe('in_progress');
});

test('an average target takes the latest approved level, and the sql agrees', function () {
    $target = PlanIndicator::factory()
        ->for(Indicator::factory()->state(['measure' => IndicatorMeasure::Average]))
        ->create(['target' => 150]);
    Report::factory()->approved()->for($target)->create(['value' => 120, 'planned_date' => '2026-03-31']);
    Report::factory()->approved()->for($target)->create(['value' => 140, 'planned_date' => '2026-06-30']);
    Report::factory()->reviewed()->for($target)->create(['value' => 160, 'planned_date' => '2026-09-30']);

    $row = PlanIndicator::query()
        ->whereKey($target->id)
        ->selectRaw(PlanIndicator::achievedValueSql().' as achieved, '.PlanIndicator::pendingValueSql().' as pending')
        ->toBase()
        ->first();

    expect($target->achievedValue())->toBe(140.0)
        ->and($target->pendingValue())->toBe(0.0)
        ->and((float) $row->achieved)->toBe(140.0)
        ->and((float) $row->pending)->toBe(0.0);
});

test('a target with nothing approved has not started, and a zero target has no percentage', function () {
    $target = PlanIndicator::factory()->create(['target' => 0]);
    Report::factory()->for($target)->create(['value' => 10]);

    expect($target->achievementStatus())->toBe('not_started')
        ->and($target->percentAchieved())->toBeNull();
});

test('the achievement sql agrees with the model for both measures', function () {
    $numeric = PlanIndicator::factory()->create(['target' => 100]);
    Report::factory()->approved()->for($numeric)->create(['value' => 60]);
    Report::factory()->approved()->for($numeric)->create(['value' => 50]);
    Report::factory()->for($numeric)->create(['value' => 5]);

    $percentage = PlanIndicator::factory()
        ->for(Indicator::factory()->state(['measure' => IndicatorMeasure::Percentage]))
        ->create(['target' => 80]);
    Report::factory()->approved()->for($percentage)->create(['value' => 30, 'planned_date' => '2026-01-31']);
    Report::factory()->approved()->for($percentage)->create(['value' => 45, 'planned_date' => '2026-02-28']);

    foreach ([$numeric, $percentage] as $target) {
        $row = PlanIndicator::query()
            ->whereKey($target->id)
            ->selectRaw(PlanIndicator::achievedValueSql().' as achieved, '.PlanIndicator::pendingValueSql().' as pending')
            ->toBase()
            ->first();

        expect((float) $row->achieved)->toBe($target->achievedValue())
            ->and((float) $row->pending)->toBe($target->pendingValue())
            ->and(PlanIndicator::query()->whereKey($target->id)->whereRaw(PlanIndicator::achievementStatusSql($target->achievementStatus()))->exists())->toBeTrue();
    }

    expect($numeric->achievementStatus())->toBe('achieved');
});

test('a target that fits its chain reports no issues', function () {
    $program = Program::factory()->create();
    $plan = AnnualPlan::factory()->for($program)->create();
    $project = Project::factory()->for($program)->create();
    $indicator = Indicator::factory()->for(Result::factory()->for($program))->create(['project_id' => $project->id]);

    expect(PlanIndicator::chainIssuesFor($plan, $indicator->load('result'), $project))->toBe([]);
});

test('a target that does not fit its chain names every broken rule', function () {
    $plan = AnnualPlan::factory()->create();
    $otherProject = Project::factory()->create();
    $indicator = Indicator::factory()
        ->for(Result::factory()->for(Program::factory()))
        ->create(['project_id' => Project::factory()->create()->id]);

    expect(PlanIndicator::chainIssuesFor($plan, $indicator->load('result'), $otherProject))->toBe([
        'targets.issue_project_differs_from_indicator',
        'targets.issue_plan_program_differs_from_result',
        'targets.issue_project_outside_plan_program',
    ]);
});

test('a proposal target may only be delivered by a project the proposal funds', function () {
    $proposal = Proposal::factory()->create();
    $funded = Project::factory()->create();
    $proposal->projects()->attach($funded);
    $indicator = Indicator::factory()->create();

    expect(PlanIndicator::chainIssuesFor($proposal, $indicator, $funded))->toBe([])
        ->and(PlanIndicator::chainIssuesFor($proposal, $indicator, Project::factory()->create()))
        ->toBe(['targets.issue_project_not_on_proposal']);
});
