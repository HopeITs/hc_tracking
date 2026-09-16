<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

test('a planned target defaults to a quarterly period and no project', function () {
    $indicatorId = insertIndicator(insertResult());

    $plan = DB::table('plan_indicators')->find(insertPlanIndicator($indicatorId, 'annual_plan', 1));

    expect($plan->period)->toBe('quarterly')
        ->and($plan->project_id)->toBeNull()
        ->and($plan->target)->toBe('12000.00');
});

test('a target keeps its exact value at the top of the range', function () {
    $indicatorId = insertIndicator(insertResult());

    $plan = DB::table('plan_indicators')->find(
        insertPlanIndicator($indicatorId, 'annual_plan', 1, target: '9999999999.99')
    );

    expect($plan->target)->toBe('9999999999.99');
});

test('deleting an indicator deletes the targets planned against it', function () {
    $indicatorId = insertIndicator(insertResult());
    insertPlanIndicator($indicatorId, 'annual_plan', 1);

    DB::table('indicators')->where('id', $indicatorId)->delete();

    $this->assertDatabaseCount('plan_indicators', 0);
});

test('deleting a project detaches its targets instead of deleting them', function () {
    $user = User::factory()->create();
    $projectId = insertProject(insertCity(), insertProgram(insertStrategy(), $user->id), $user->id);
    $indicatorId = insertIndicator(insertResult());
    $planId = insertPlanIndicator($indicatorId, 'annual_plan', 1, $projectId);

    DB::table('projects')->where('id', $projectId)->delete();

    $this->assertDatabaseHas('plan_indicators', ['id' => $planId, 'project_id' => null]);
});

/**
 * The observer on AnnualPlan and Proposal covers Eloquent deletes only. A raw
 * query-builder delete fires no model events, so the orphan is still possible
 * by that route and no foreign key can stop it. See PlanIndicatorCleanupTest
 * for the covered paths.
 */
test('a target outlives a source deleted through the query builder', function () {
    $user = User::factory()->create();
    $programId = insertProgram(insertStrategy(), $user->id);
    $annualPlanId = DB::table('annual_plans')->insertGetId([
        'program_id' => $programId,
        'manager_id' => $user->id,
        'year' => 2026,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $planId = insertPlanIndicator(insertIndicator(insertResult()), 'annual_plan', $annualPlanId);

    DB::table('programs')->where('id', $programId)->delete();

    $this->assertDatabaseCount('annual_plans', 0);
    $this->assertDatabaseHas('plan_indicators', ['id' => $planId, 'source_id' => $annualPlanId]);
});
