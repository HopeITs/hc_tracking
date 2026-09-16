<?php

use App\Models\AnnualPlan;
use App\Models\HrEmployee;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\User;

test('a annual plan belongs to a program and an hr employee manager', function () {
    $program = Program::factory()->create();
    $managerId = insertHrEmployee();

    $plan = AnnualPlan::factory()->create([
        'program_id' => $program->id,
        'manager_id' => $managerId,
    ]);

    expect($plan->program->id)->toBe($program->id)
        ->and($plan->manager)->toBeInstanceOf(HrEmployee::class)
        ->and($plan->manager->id)->toBe($managerId);
});

test('a annual plan has the targets committed in it', function () {
    $plan = AnnualPlan::factory()->create();

    PlanIndicator::factory(2)->forAnnualPlan($plan)->create();
    PlanIndicator::factory()->create();

    expect($plan->planIndicators)->toHaveCount(2)
        ->and($plan->planIndicators->first())->toBeInstanceOf(PlanIndicator::class);
});

test('a annual plan casts its year to an integer', function () {
    $plan = AnnualPlan::factory()->create(['year' => 2026])->fresh();

    expect($plan->year)->toBe(2026);
});

test('the forYears scope keeps only the plans inside an inclusive range', function () {
    AnnualPlan::factory()->create(['year' => 2023]);
    AnnualPlan::factory()->create(['year' => 2024]);
    AnnualPlan::factory()->create(['year' => 2026]);
    AnnualPlan::factory()->create(['year' => 2027]);

    $years = AnnualPlan::query()->forYears(2024, 2026)->orderBy('year')->pluck('year')->all();

    expect($years)->toBe([2024, 2026]);
});

test('the forYears scope orders a reversed range instead of returning nothing', function () {
    AnnualPlan::factory()->create(['year' => 2025]);

    expect(AnnualPlan::query()->forYears(2026, 2024)->count())->toBe(1);
});

test('the managedBy scope keeps one manager plans and passes a null id through untouched', function () {
    $manager = User::factory()->create();
    AnnualPlan::factory()->create(['manager_id' => $manager->id]);
    AnnualPlan::factory()->create();

    expect(AnnualPlan::query()->managedBy($manager->id)->count())->toBe(1)
        ->and(AnnualPlan::query()->managedBy(null)->count())->toBe(2);
});
