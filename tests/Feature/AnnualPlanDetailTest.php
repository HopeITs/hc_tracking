<?php

use App\Enums\ResultType;
use App\Models\AnnualPlan;
use App\Models\Indicator;
use App\Models\Milestone;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Result;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected from annual plan detail', function () {
    $plan = AnnualPlan::factory()->create();

    $this->get(route('annual-plans.show', $plan))->assertRedirect(route('login'));
});

test('annual plan detail shows the plan its program manager and year', function () {
    $user = User::factory()->role('General Manager')->create();
    $manager = insertHrEmployee(firstNameEn: 'Layla', lastNameEn: 'Haddad');
    $program = Program::factory()->create(['name' => 'Water Security']);
    $plan = AnnualPlan::factory()->create([
        'program_id' => $program->id,
        'manager_id' => $manager,
        'year' => 2026,
    ]);

    $this->actingAs($user)
        ->get(route('annual-plans.show', $plan))
        ->assertOk()
        ->assertSeeText('Annual plan 2026 · Water Security')
        ->assertSeeText('Layla Haddad');
});

test('annual plan detail lists targets committed in that plan only', function () {
    $user = User::factory()->role('General Manager')->create();
    $plan = AnnualPlan::factory()->create();
    $otherPlan = AnnualPlan::factory()->create();
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
        'source_type' => 'annual_plan',
        'source_id' => $plan->id,
        'indicator_id' => $indicator->id,
        'target' => '12000.00',
    ]);
    PlanIndicator::factory()->create([
        'source_type' => 'annual_plan',
        'source_id' => $otherPlan->id,
        'indicator_id' => $otherIndicator->id,
    ]);

    $this->actingAs($user)
        ->get(route('annual-plans.show', $plan))
        ->assertOk()
        ->assertSeeText('People reached with safe water')
        ->assertDontSeeText('Latrines constructed')
        ->assertSeeText('12,000.00');
});

test('annual plan detail renders a plan with no targets', function () {
    $user = User::factory()->role('General Manager')->create();
    $plan = AnnualPlan::factory()->create(['year' => 2027]);

    $this->actingAs($user)
        ->get(route('annual-plans.show', $plan))
        ->assertOk()
        ->assertSeeText('No targets yet');
});

test('a target with no project reads as organisation wide', function () {
    $user = User::factory()->role('General Manager')->create();
    $plan = AnnualPlan::factory()->create();
    $indicator = Indicator::factory()->create();

    PlanIndicator::factory()->create([
        'source_type' => 'annual_plan',
        'source_id' => $plan->id,
        'indicator_id' => $indicator->id,
        'project_id' => null,
    ]);

    $this->actingAs($user)
        ->get(route('annual-plans.show', $plan))
        ->assertOk()
        ->assertSeeText('Organisation-wide');
});

test('annual plan chain shows the activities with milestones planned that year and this plan\'s targets only', function () {
    $user = User::factory()->role('General Manager')->create();
    $program = Program::factory()->create();
    $plan = AnnualPlan::factory()->create(['program_id' => $program->id, 'year' => 2026]);
    $lastYear = AnnualPlan::factory()->create(['program_id' => $program->id, 'year' => 2025]);
    $project = Project::factory()->create(['program_id' => $program->id]);
    $output = Result::factory()->type(ResultType::Output)->create(['program_id' => $program->id, 'statement' => 'Wells are rehabilitated.']);
    $activities = [];

    foreach (['Activity planned this year.' => '2026-05-01', 'Activity planned last year.' => '2025-05-01', 'Activity without a milestone.' => null] as $statement => $date) {
        $activities[$statement] = Result::factory()->activity($output)->create(['statement' => $statement]);

        if ($date !== null) {
            Milestone::factory()->create(['project_id' => $project->id, 'planned_start_date' => $date, 'planned_end_date' => $date])
                ->forceFill(['result_id' => $activities[$statement]->id])->saveQuietly();
        }
    }

    $indicator = Indicator::factory()->create(['result_id' => $output->id]);
    $planTarget = PlanIndicator::factory()->create(['source_id' => $plan->id, 'indicator_id' => $indicator->id, 'project_id' => $project->id]);
    $oldTarget = PlanIndicator::factory()->create(['source_id' => $lastYear->id, 'indicator_id' => $indicator->id, 'project_id' => $project->id]);

    Livewire::actingAs($user)
        ->test('pages::annual-plans.show', ['annualPlan' => $plan])
        ->assertSeeHtml('data-chain-view')
        ->assertSeeInOrder(['Wells are rehabilitated.', 'Activity planned this year.'])
        ->assertDontSee('Activity planned last year.')
        ->assertDontSee('Activity without a milestone.')
        ->assertSeeHtml('data-chain-target="'.$planTarget->id.'"')
        ->assertDontSeeHtml('data-chain-target="'.$oldTarget->id.'"');
});
