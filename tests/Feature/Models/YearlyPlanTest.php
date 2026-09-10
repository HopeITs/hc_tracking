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

test('the forYears scope keeps only the plans inside an inclusive range', function () {
    YearlyPlan::factory()->create(['year' => 2023]);
    YearlyPlan::factory()->create(['year' => 2024]);
    YearlyPlan::factory()->create(['year' => 2026]);
    YearlyPlan::factory()->create(['year' => 2027]);

    $years = YearlyPlan::query()->forYears(2024, 2026)->orderBy('year')->pluck('year')->all();

    expect($years)->toBe([2024, 2026]);
});

test('the forYears scope orders a reversed range instead of returning nothing', function () {
    YearlyPlan::factory()->create(['year' => 2025]);

    expect(YearlyPlan::query()->forYears(2026, 2024)->count())->toBe(1);
});

test('the managedBy scope keeps one manager plans and passes a null id through untouched', function () {
    $manager = User::factory()->create();
    YearlyPlan::factory()->create(['manager_id' => $manager->id]);
    YearlyPlan::factory()->create();

    expect(YearlyPlan::query()->managedBy($manager->id)->count())->toBe(1)
        ->and(YearlyPlan::query()->managedBy(null)->count())->toBe(2);
});
