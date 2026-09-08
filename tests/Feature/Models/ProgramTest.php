<?php

use App\Models\Program;
use App\Models\Project;
use App\Models\Result;
use App\Models\Strategy;
use App\Models\User;
use App\Models\YearlyPlan;

test('a program belongs to a strategy and a manager', function () {
    $strategy = Strategy::factory()->create();
    $manager = User::factory()->create();

    $program = Program::factory()->create([
        'strategy_id' => $strategy->id,
        'manager_id' => $manager->id,
    ]);

    expect($program->strategy)->toBeInstanceOf(Strategy::class)
        ->and($program->strategy->id)->toBe($strategy->id)
        ->and($program->manager)->toBeInstanceOf(User::class)
        ->and($program->manager->id)->toBe($manager->id);
});

test('a program has its projects, yearly plans and results', function () {
    $program = Program::factory()->create();

    Project::factory(2)->create(['program_id' => $program->id]);
    YearlyPlan::factory()->create(['program_id' => $program->id, 'year' => 2026]);
    YearlyPlan::factory()->create(['program_id' => $program->id, 'year' => 2027]);
    Result::factory()->create(['program_id' => $program->id]);

    expect($program->projects)->toHaveCount(2)
        ->and($program->yearlyPlans)->toHaveCount(2)
        ->and($program->results)->toHaveCount(1);
});
