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
