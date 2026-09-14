<?php

use App\Models\Indicator;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Result;
use App\Models\User;
use App\Models\YearlyPlan;

test('guests are redirected from annual plan detail', function () {
    $plan = YearlyPlan::factory()->create();

    $this->get(route('yearly-plans.show', $plan))->assertRedirect(route('login'));
});

test('annual plan detail shows the plan its program manager and year', function () {
    $user = User::factory()->create();
    $manager = insertHrEmployee(firstNameEn: 'Layla', lastNameEn: 'Haddad');
    $program = Program::factory()->create(['name' => 'Water Security']);
    $plan = YearlyPlan::factory()->create([
        'program_id' => $program->id,
        'manager_id' => $manager,
        'year' => 2026,
    ]);

    $this->actingAs($user)
        ->get(route('yearly-plans.show', $plan))
        ->assertOk()
        ->assertSeeText('FY 2026 · Water Security')
        ->assertSeeText('Layla Haddad');
});

test('annual plan detail lists targets committed in that plan only', function () {
    $user = User::factory()->create();
    $plan = YearlyPlan::factory()->create();
    $otherPlan = YearlyPlan::factory()->create();
    $result = Result::factory()->create();
    $indicator = Indicator::factory()->create([
        'result_id' => $result->id,
        'name' => 'People reached with safe water',
    ]);
    $otherIndicator = Indicator::factory()->create([
        'result_id' => $result->id,
        'name' => 'Latrines constructed',
    ]);

    PlanIndicator::factory()->create([
        'source_type' => 'yearly_plan',
        'source_id' => $plan->id,
        'indicator_id' => $indicator->id,
        'target' => '12000.00',
    ]);
    PlanIndicator::factory()->create([
        'source_type' => 'yearly_plan',
        'source_id' => $otherPlan->id,
        'indicator_id' => $otherIndicator->id,
    ]);

    $this->actingAs($user)
        ->get(route('yearly-plans.show', $plan))
        ->assertOk()
        ->assertSeeText('People reached with safe water')
        ->assertDontSeeText('Latrines constructed')
        ->assertSeeText('12,000.00');
});

test('annual plan detail renders a plan with no targets', function () {
    $user = User::factory()->create();
    $plan = YearlyPlan::factory()->create(['year' => 2027]);

    $this->actingAs($user)
        ->get(route('yearly-plans.show', $plan))
        ->assertOk()
        ->assertSeeText('No targets yet');
});

test('a target with no project reads as organisation wide', function () {
    $user = User::factory()->create();
    $plan = YearlyPlan::factory()->create();
    $indicator = Indicator::factory()->create();

    PlanIndicator::factory()->create([
        'source_type' => 'yearly_plan',
        'source_id' => $plan->id,
        'indicator_id' => $indicator->id,
        'project_id' => null,
    ]);

    $this->actingAs($user)
        ->get(route('yearly-plans.show', $plan))
        ->assertOk()
        ->assertSeeText('Organisation-wide');
});
