<?php

use App\Models\Activity;
use App\Models\Indicator;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\ProposalLocation;
use App\Models\Result;

test('an indicator belongs to a result', function () {
    $result = Result::factory()->create();
    $indicator = Indicator::factory()->create(['result_id' => $result->id]);

    expect($indicator->result)->toBeInstanceOf(Result::class)
        ->and($indicator->result->id)->toBe($result->id);
});

test('an indicator has the targets planned against it', function () {
    $indicator = Indicator::factory()->create();

    PlanIndicator::factory(2)->create(['indicator_id' => $indicator->id]);
    PlanIndicator::factory()->create();

    expect($indicator->planIndicators)->toHaveCount(2);
});

/*
 * Y-16 slice 1 (D-93): an instance's activity reads its location row. The
 * project answers only while a row has not taken over (an instance the
 * backfill could not map, D-80), and Y-18 drops that fallback.
 */

test('an instance with no result program takes the program of its location row', function () {
    $program = Program::factory()->create();
    $row = ProposalLocation::factory()->create(['program_id' => $program->id]);
    $indicator = Indicator::factory()
        ->for(Result::factory()->create(['program_id' => null]))
        ->create(['proposal_location_id' => $row->id, 'project_id' => null]);

    expect($indicator->activityProgramId())->toBe($program->id);
});

test('an instance with no row falls back to the program of its project', function () {
    $program = Program::factory()->create();
    $project = Project::factory()->for($program)->create();
    $indicator = Indicator::factory()
        ->for(Result::factory()->create(['program_id' => null]))
        ->create(['project_id' => $project->id, 'proposal_location_id' => null]);

    expect($indicator->activityProgramId())->toBe($program->id);
});

test('the deleted event remembers the responsible of the location row', function () {
    $employeeId = insertHrEmployee();
    $row = ProposalLocation::factory()->create(['responsible_employee_id' => $employeeId]);
    $indicator = Indicator::factory()->create(['proposal_location_id' => $row->id, 'project_id' => null]);

    $indicator->delete();

    $properties = Activity::query()
        ->where('subject_type', 'indicator')
        ->where('subject_id', $indicator->id)
        ->where('event', 'indicator.deleted')
        ->value('properties');

    expect($properties['employee_ids'])->toBe([$employeeId]);
});
