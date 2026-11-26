<?php

use App\Enums\IndicatorMeasure;
use App\Models\AnnualPlan;
use App\Models\Indicator;
use App\Models\LibraryIndicator;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\Report;
use App\Models\Result;
use App\Support\Reporting\ReportPlace;
use Carbon\CarbonImmutable;

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

test('achievement through a due date includes only approved reports dated on or before it', function (IndicatorMeasure $measure, float $expected) {
    $indicator = Indicator::factory()->create(['measure' => $measure]);
    $target = PlanIndicator::factory()->for($indicator)->create();
    Report::factory()->approved()->for($target)->create(['value' => 10, 'planned_date' => '2026-06-01']);
    $onDueDate = Report::factory()->approved()->for($target)->create(['value' => 30, 'planned_date' => '2026-06-15']);
    Report::factory()->approved()->for($target)->create(['value' => 90, 'planned_date' => '2026-06-16']);
    Report::factory()->reviewed()->for($target)->create(['value' => 100, 'planned_date' => '2026-06-10']);

    $dueDate = CarbonImmutable::parse('2026-06-15');
    expect($target->achievedValue($dueDate))->toBe($expected);

    $onDueDate->update(['value' => 20]);
    expect($target->fresh()->achievedValue($dueDate))->toBe($measure === IndicatorMeasure::Numeric ? 30.0 : 20.0);
})->with([
    'numeric counts' => [IndicatorMeasure::Numeric, 40.0],
    'percentage levels' => [IndicatorMeasure::Percentage, 30.0],
    'average levels' => [IndicatorMeasure::Average, 30.0],
]);

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

test('an annual plan cannot target an indicator of a proposal framework', function () {
    $program = Program::factory()->create();
    $plan = AnnualPlan::factory()->for($program)->create();
    $proposalResult = Result::factory()->for($program)->create(['proposal_id' => Proposal::factory()->create()->id]);
    $indicator = Indicator::factory()->for($proposalResult)->create();

    expect(PlanIndicator::chainIssuesFor($plan, $indicator->load('result'), null))
        ->toBe(['targets.issue_plan_target_on_proposal_framework']);
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

test('an approved NULL value (Not measured) adds nothing and never becomes the latest level', function (IndicatorMeasure $measure, float $expected) {
    $target = PlanIndicator::factory()
        ->for(Indicator::factory()->create(['measure' => $measure]))
        ->create(['target' => 100]);
    Report::factory()->approved()->for($target)->create(['value' => 40, 'planned_date' => '2026-10-31']);
    Report::factory()->approved()->for($target)->create(['value' => null, 'planned_date' => '2026-11-30']);

    $row = PlanIndicator::query()
        ->whereKey($target->id)
        ->selectRaw(PlanIndicator::achievedValueSql().' as achieved')
        ->toBase()
        ->first();

    expect($target->achievedValue())->toBe($expected)
        ->and((float) $row->achieved)->toBe($expected);
})->with([
    'count' => [IndicatorMeasure::Numeric, 40.0],
    'percentage' => [IndicatorMeasure::Percentage, 40.0],
]);

/**
 * A proposal target with the given place columns and no project.
 *
 * @param  array<string, mixed>  $columns
 */
function placedTarget(array $columns, ?Proposal $proposal = null): PlanIndicator
{
    return PlanIndicator::factory()->forProposal($proposal)->create(['project_id' => null, ...$columns]);
}

test('a target without a city has no place', function () {
    expect(placedTarget(['program_id' => Program::factory()->create()->id, 'center_id' => 5])->place())->toBeNull();
});

test('a shared target has no single place', function () {
    $target = placedTarget(['city_id' => 7, 'program_id' => Program::factory()->create()->id, 'all_centers' => true]);

    expect($target->place())->toBeNull();
});

test('the place is built from the target columns', function () {
    $programId = Program::factory()->create()->id;
    $center = placedTarget(['city_id' => 7, 'program_id' => $programId, 'center_id' => 9])->fresh();
    $cityWide = placedTarget(['city_id' => 7, 'program_id' => $programId, 'city_wide' => true])->fresh();

    expect($center->place()?->key())->toBe("7:{$programId}:c9")
        ->and($cityWide->place()?->key())->toBe("7:{$programId}:city-wide");
});

test('atPlace finds the target at its center and not at another', function () {
    $programId = Program::factory()->create()->id;
    $target = placedTarget(['city_id' => 7, 'program_id' => $programId, 'center_id' => 9]);
    placedTarget(['city_id' => 7, 'program_id' => $programId, 'center_id' => 10]);

    $found = PlanIndicator::query()->atPlace(new ReportPlace(7, $programId, 9, false))->pluck('id')->all();

    expect($found)->toBe([$target->id]);
});

test('a city-wide target matches the city-wide place of its city and program only', function () {
    $programId = Program::factory()->create()->id;
    $target = placedTarget(['city_id' => 7, 'program_id' => $programId, 'city_wide' => true]);
    placedTarget(['city_id' => 7, 'program_id' => $programId, 'center_id' => 9]);

    expect(PlanIndicator::query()->atPlace(new ReportPlace(7, $programId, null, true))->pluck('id')->all())->toBe([$target->id])
        ->and(PlanIndicator::query()->atPlace(new ReportPlace(8, $programId, null, true))->exists())->toBeFalse();
});

test('a target whose center is not set matches nothing, not even an unset place', function () {
    $programId = Program::factory()->create()->id;
    $target = placedTarget(['city_id' => 7, 'program_id' => $programId]);
    $unset = new ReportPlace(7, $programId, null, false);

    expect(PlanIndicator::query()->atPlace($unset)->exists())->toBeFalse()
        ->and($target->coversPlace($unset))->toBeFalse();
});

test('a shared target is not found by atPlace', function () {
    $programId = Program::factory()->create()->id;
    placedTarget(['city_id' => 7, 'program_id' => $programId, 'all_centers' => true]);

    expect(PlanIndicator::query()->atPlace(new ReportPlace(7, $programId, null, false))->exists())->toBeFalse();
});

test('a target covers its own place', function () {
    $programId = Program::factory()->create()->id;
    $target = placedTarget(['city_id' => 7, 'program_id' => $programId, 'center_id' => 9]);

    expect($target->coversPlace(new ReportPlace(7, $programId, 9, false)))->toBeTrue()
        ->and($target->coversPlace(new ReportPlace(7, $programId, 10, false)))->toBeFalse();
});

test('a shared target covers the centers of its row and no third center', function () {
    $programId = Program::factory()->create()->id;
    $proposal = Proposal::factory()->create();
    $row = ProposalLocation::factory()->create(['proposal_id' => $proposal->id, 'city_id' => 7, 'program_id' => $programId, 'city_wide' => false]);
    $row->centers()->create(['center_id' => 9]);
    $row->centers()->create(['center_id' => 10]);
    $target = placedTarget(['city_id' => 7, 'program_id' => $programId, 'all_centers' => true], $proposal);

    expect($target->coversPlace(new ReportPlace(7, $programId, 9, false)))->toBeTrue()
        ->and($target->coversPlace(new ReportPlace(7, $programId, 10, false)))->toBeTrue()
        ->and($target->coversPlace(new ReportPlace(7, $programId, 11, false)))->toBeFalse()
        ->and($target->coversPlace(new ReportPlace(7, $programId, null, true)))->toBeFalse();
});

/*
 * Y-16 slice 1 (D-93): the row-keyed twins of the chain rules and the window
 * scope. A row is of one proposal, so "funded by the proposal" becomes
 * "belongs to the proposal" and needs no pivot.
 */

test('a target on the row its indicator is scoped to reports no issues', function () {
    $program = Program::factory()->create();
    $plan = AnnualPlan::factory()->for($program)->create();
    $row = ProposalLocation::factory()->create(['program_id' => $program->id]);
    $indicator = Indicator::factory()
        ->for(Result::factory()->for($program))
        ->create(['proposal_location_id' => $row->id]);

    expect(PlanIndicator::chainIssuesForRow($plan, $indicator->load('result'), $row))->toBe([]);
});

test('a target on another row than its indicator names every broken rule', function () {
    $plan = AnnualPlan::factory()->create();
    $otherRow = ProposalLocation::factory()->create(['program_id' => Program::factory()->create()->id]);
    $indicator = Indicator::factory()
        ->for(Result::factory()->for(Program::factory()))
        ->create(['proposal_location_id' => ProposalLocation::factory()->create()->id]);

    expect(PlanIndicator::chainIssuesForRow($plan, $indicator->load('result'), $otherRow))->toBe([
        'targets.issue_project_differs_from_indicator',
        'targets.issue_plan_program_differs_from_result',
        'targets.issue_project_outside_plan_program',
    ]);
});

test('an annual plan cannot target an indicator of a proposal framework, by row', function () {
    $program = Program::factory()->create();
    $plan = AnnualPlan::factory()->for($program)->create();
    $proposalResult = Result::factory()->for($program)->create(['proposal_id' => Proposal::factory()->create()->id]);
    $indicator = Indicator::factory()->for($proposalResult)->create();

    expect(PlanIndicator::chainIssuesForRow($plan, $indicator->load('result'), null))
        ->toBe(['targets.issue_plan_target_on_proposal_framework']);
});

test('a proposal target may only be carried by a row of that proposal', function () {
    $proposal = Proposal::factory()->create();
    $own = ProposalLocation::factory()->create(['proposal_id' => $proposal->id]);
    $foreign = ProposalLocation::factory()->create();
    $indicator = Indicator::factory()->create();

    expect(PlanIndicator::chainIssuesForRow($proposal, $indicator, $own))->toBe([])
        ->and(PlanIndicator::chainIssuesForRow($proposal, $indicator, $foreign))
        ->toBe(['targets.issue_project_not_on_proposal']);
});

test('forPlaceInWindow finds a proposal target at the place whose proposal runs inside the window', function () {
    $proposal = Proposal::factory()->create([
        'actual_date_begin' => '2026-01-01',
        'actual_date_end' => '2026-12-31',
    ]);
    $program = Program::factory()->create();
    $place = new ReportPlace(7, $program->id, 11, false);

    $target = PlanIndicator::factory()->forProposal($proposal)->create([
        'city_id' => 7,
        'program_id' => $program->id,
        'center_id' => 11,
        'city_wide' => false,
    ]);

    $inside = PlanIndicator::query()
        ->forPlaceInWindow($place, CarbonImmutable::parse('2026-06-01'), CarbonImmutable::parse('2026-06-30'))
        ->pluck('plan_indicators.id')->all();

    $outside = PlanIndicator::query()
        ->forPlaceInWindow($place, CarbonImmutable::parse('2027-02-01'), CarbonImmutable::parse('2027-02-28'))
        ->count();

    expect($inside)->toBe([$target->id])->and($outside)->toBe(0);
});

test('forPlaceInWindow ignores a target of another place', function () {
    $proposal = Proposal::factory()->create([
        'actual_date_begin' => '2026-01-01',
        'actual_date_end' => '2026-12-31',
    ]);
    $program = Program::factory()->create();

    PlanIndicator::factory()->forProposal($proposal)->create([
        'city_id' => 7,
        'program_id' => $program->id,
        'center_id' => 99,
        'city_wide' => false,
    ]);

    expect(PlanIndicator::query()
        ->forPlaceInWindow(new ReportPlace(7, $program->id, 11, false), CarbonImmutable::parse('2026-06-01'), CarbonImmutable::parse('2026-06-30'))
        ->count())->toBe(0);
});

test('previousYearReference matches last year by place, so a new row at the same place still finds it', function () {
    $program = Program::factory()->create();
    $library = LibraryIndicator::factory()->create()->id;

    $place = ['city_id' => 7, 'program_id' => $program->id, 'center_id' => 11, 'city_wide' => false];

    $previous = Proposal::factory()->create();
    $oldTarget = PlanIndicator::factory()->forProposal($previous)->create([
        'indicator_id' => Indicator::factory()->create(['library_indicator_id' => $library])->id,
        'target' => 100,
        ...$place,
    ]);

    // The renewal is a different proposal with a different row (D-93), at the
    // same place. The old project is nowhere in the match.
    $renewal = Proposal::factory()->create(['previous_proposal_id' => $previous->id]);
    $newTarget = PlanIndicator::factory()->forProposal($renewal)->create([
        'indicator_id' => Indicator::factory()->create(['library_indicator_id' => $library])->id,
        'proposal_location_id' => ProposalLocation::factory()->create(['proposal_id' => $renewal->id])->id,
        'project_id' => null,
        'target' => 200,
        ...$place,
    ]);

    expect(PlanIndicator::previousYearReference($newTarget))
        ->toBe(['value' => $oldTarget->achievedValue(), 'percent' => $oldTarget->percentAchieved()]);
});

test('previousYearReference ignores last year a target at another place', function () {
    $program = Program::factory()->create();
    $library = LibraryIndicator::factory()->create()->id;

    $previous = Proposal::factory()->create();
    PlanIndicator::factory()->forProposal($previous)->create([
        'indicator_id' => Indicator::factory()->create(['library_indicator_id' => $library])->id,
        'city_id' => 7, 'program_id' => $program->id, 'center_id' => 99, 'city_wide' => false,
    ]);

    $renewal = Proposal::factory()->create(['previous_proposal_id' => $previous->id]);
    $newTarget = PlanIndicator::factory()->forProposal($renewal)->create([
        'indicator_id' => Indicator::factory()->create(['library_indicator_id' => $library])->id,
        'project_id' => null,
        'city_id' => 7, 'program_id' => $program->id, 'center_id' => 11, 'city_wide' => false,
    ]);

    expect(PlanIndicator::previousYearReference($newTarget))->toBeNull();
});

test('previousYearReference matches a shared target with a shared target', function () {
    $program = Program::factory()->create();
    $library = LibraryIndicator::factory()->create()->id;

    $shared = ['city_id' => 7, 'program_id' => $program->id, 'center_id' => null, 'city_wide' => false, 'all_centers' => true];

    $previous = Proposal::factory()->create();
    $oldTarget = PlanIndicator::factory()->forProposal($previous)->create([
        'indicator_id' => Indicator::factory()->create(['library_indicator_id' => $library])->id,
        ...$shared,
    ]);

    $renewal = Proposal::factory()->create(['previous_proposal_id' => $previous->id]);
    $newTarget = PlanIndicator::factory()->forProposal($renewal)->create([
        'indicator_id' => Indicator::factory()->create(['library_indicator_id' => $library])->id,
        'project_id' => null,
        ...$shared,
    ]);

    expect(PlanIndicator::previousYearReference($newTarget)['value'])->toBe($oldTarget->achievedValue());
});
