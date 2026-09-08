<?php

use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\User;
use App\Models\YearlyPlan;

test('a yearly plan belongs to a program and a manager', function () {
    $program = Program::factory()->create();
    $manager = User::factory()->create();

    $plan = YearlyPlan::factory()->create([
        'program_id' => $program->id,
        'manager_id' => $manager->id,
    ]);

    expect($plan->program->id)->toBe($program->id)
        ->and($plan->manager)->toBeInstanceOf(User::class)
        ->and($plan->manager->id)->toBe($manager->id);
});

test('a yearly plan has the targets committed in it', function () {
    $plan = YearlyPlan::factory()->create();

    PlanIndicator::factory(2)->forYearlyPlan($plan)->create();
    PlanIndicator::factory()->create();

    expect($plan->planIndicators)->toHaveCount(2)
        ->and($plan->planIndicators->first())->toBeInstanceOf(PlanIndicator::class);
});

test('a yearly plan casts its year to an integer', function () {
    $plan = YearlyPlan::factory()->create(['year' => 2026])->fresh();

    expect($plan->year)->toBe(2026);
});
