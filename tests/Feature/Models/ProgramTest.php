<?php

use App\Models\AnnualPlan;
use App\Models\HrEmployee;
use App\Models\Program;
use App\Models\Project;
use App\Models\Result;
use App\Models\Strategy;

test('a program belongs to a strategy and an hr employee manager', function () {
    $strategy = Strategy::factory()->create();
    $managerId = insertHrEmployee();

    $program = Program::factory()->create([
        'strategy_id' => $strategy->id,
        'manager_id' => $managerId,
    ]);

    expect($program->strategy)->toBeInstanceOf(Strategy::class)
        ->and($program->strategy->id)->toBe($strategy->id)
        ->and($program->manager)->toBeInstanceOf(HrEmployee::class)
        ->and($program->manager->id)->toBe($managerId);
});

test('a program has its projects, annual plans and results', function () {
    $program = Program::factory()->create();

    Project::factory(2)->create(['program_id' => $program->id]);
    AnnualPlan::factory()->create(['program_id' => $program->id, 'year' => 2026]);
    AnnualPlan::factory()->create(['program_id' => $program->id, 'year' => 2027]);
    Result::factory()->create(['program_id' => $program->id]);

    expect($program->projects)->toHaveCount(2)
        ->and($program->annualPlans)->toHaveCount(2)
        ->and($program->results)->toHaveCount(1);
});

test('programs sort by their hr name in either direction', function () {
    $shelter = Program::factory()->create(['name' => 'Shelter']);
    $health = Program::factory()->create(['name' => 'Health']);
    $wash = Program::factory()->create(['name' => 'WASH']);

    $ascending = Program::query()->orderByName()->pluck('id')->all();
    $descending = Program::query()->orderByName('desc')->pluck('id')->all();

    expect($ascending)->toBe([$health->id, $shelter->id, $wash->id])
        ->and($descending)->toBe([$wash->id, $shelter->id, $health->id]);
});
