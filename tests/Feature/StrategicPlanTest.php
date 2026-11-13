<?php

use App\Enums\IndicatorMeasure;
use App\Enums\ResultType;
use App\Enums\StrategicPlanStatus;
use App\Models\Indicator;
use App\Models\Program;
use App\Models\Proposal;
use App\Models\Result;
use App\Models\StrategicPlan;
use App\Models\Strategy;
use App\Models\User;
use App\Models\UserNotification;
use Livewire\Livewire;

beforeEach(function () {
    $this->strategy = Strategy::factory()->create(['name' => 'Hope 2026', 'start_year' => 2026, 'end_year' => 2027]);
    $this->managerEmployee = insertHrEmployee(firstNameEn: 'Pat', lastNameEn: 'Manager');
    $this->program = Program::factory()->create(['strategy_id' => $this->strategy->id, 'manager_id' => $this->managerEmployee]);
    $this->outcome = Result::factory()->type(ResultType::Outcome)->create(['program_id' => $this->program->id]);
    $this->indicator = Indicator::factory()->create(['result_id' => $this->outcome->id, 'name' => 'Families reached', 'measure' => IndicatorMeasure::Numeric]);
    $this->mealManager = mealManager();
    $this->programManager = userWithRole('Managers', $this->managerEmployee);
});

function strategicPlanPage(User $user)
{
    return Livewire::actingAs($user)->test('pages::strategic-plans.show', ['strategy' => test()->strategy, 'program' => test()->program]);
}

/**
 * The MEAL manager writes both years and links the indicator with a figure per year.
 */
function writeFullPlan(User $user)
{
    return strategicPlanPage($user)
        ->set('goals.2026', 'Reach the first 500 families.')
        ->set('goals.2027', 'Reach 1,200 families in all.')
        ->set('indicatorToAdd', (string) test()->indicator->id)
        ->call('addIndicator')
        ->set('targets.'.test()->indicator->id.'.2026', '500')
        ->set('targets.'.test()->indicator->id.'.2027', '1200');
}

test('the MEAL manager writes the yearly goals and figures and sends the plan to the program manager', function () {
    writeFullPlan($this->mealManager)
        ->call('save')
        ->assertHasNoErrors()
        ->call('submit')
        ->assertHasNoErrors();

    $plan = StrategicPlan::query()->with('years.targets')->sole();

    expect($plan->status)->toBe(StrategicPlanStatus::Submitted)
        ->and($plan->years->pluck('goals', 'year')->all())->toBe([2026 => 'Reach the first 500 families.', 2027 => 'Reach 1,200 families in all.'])
        ->and($plan->years->flatMap->targets->pluck('target')->all())->toBe(['500.00', '1200.00'])
        ->and(UserNotification::query()->forUser($this->programManager)->get()->pluck('data.key')->all())
        ->toBe(['notifications.events.strategic_plan.submitted']);
});

test('a MEAL officer and the program manager read the plan but cannot write it', function (string $who) {
    $user = $who === 'officer' ? userWithRole('MEAL') : $this->programManager;

    strategicPlanPage($user)
        ->assertSee(__('strategic_plans.goals_heading'))
        ->assertDontSeeHtml('data-test="save-strategic-plan"')
        ->call('save')
        ->assertForbidden();

    expect(StrategicPlan::query()->exists())->toBeFalse();
})->with(['officer', 'program manager']);

test('the program manager approves a plan waiting for them, and the MEAL manager hears of it', function () {
    writeFullPlan($this->mealManager)->call('submit');

    strategicPlanPage($this->programManager)
        ->assertSeeHtml('data-test="approve-strategic-plan"')
        ->call('approve')
        ->assertHasNoErrors();

    $plan = StrategicPlan::query()->sole();

    expect($plan->status)->toBe(StrategicPlanStatus::Approved)
        ->and($plan->approved_by)->toBe($this->programManager->id)
        ->and(UserNotification::query()->forUser($this->mealManager)->get()->pluck('data.key')->all())
        ->toBe(['notifications.events.strategic_plan.approved']);
});

test('only the program manager of that program approves', function () {
    writeFullPlan($this->mealManager)->call('submit');

    strategicPlanPage(userWithRole('Managers', insertHrEmployee(firstNameEn: 'Other')))
        ->assertDontSeeHtml('data-test="approve-strategic-plan"')
        ->call('approve')
        ->assertForbidden();
    strategicPlanPage($this->mealManager)->call('approve')->assertForbidden();

    expect(StrategicPlan::query()->sole()->status)->toBe(StrategicPlanStatus::Submitted);
});

test('the General Manager approves while the program has no manager', function () {
    $this->program->update(['manager_id' => null]);
    writeFullPlan($this->mealManager)->call('submit');

    strategicPlanPage(userWithRole('General Manager'))->call('approve')->assertHasNoErrors();

    expect(StrategicPlan::query()->sole()->status)->toBe(StrategicPlanStatus::Approved);
});

test('sending a plan back needs a reason and returns it to a draft the MEAL manager can change', function () {
    writeFullPlan($this->mealManager)->call('submit');

    strategicPlanPage($this->programManager)
        ->call('openSendBack')
        ->assertSet('showSendBackModal', true)
        ->call('sendBack')
        ->assertHasErrors(['sendBackReason'])
        ->set('sendBackReason', 'Raise the 2027 figure.')
        ->call('sendBack')
        ->assertHasNoErrors()
        ->assertSet('showSendBackModal', false);

    $plan = StrategicPlan::query()->sole();

    expect($plan->status)->toBe(StrategicPlanStatus::Draft)
        ->and($plan->sent_back_reason)->toBe('Raise the 2027 figure.');

    strategicPlanPage($this->mealManager)
        ->assertSee('Raise the 2027 figure.')
        ->set('targets.'.$this->indicator->id.'.2027', '1500')
        ->call('save')
        ->assertHasNoErrors();
});

test('a plan waiting for approval cannot change', function () {
    writeFullPlan($this->mealManager)->call('submit');

    strategicPlanPage($this->mealManager)
        ->assertDontSeeHtml('data-test="save-strategic-plan"')
        ->call('save')
        ->assertForbidden();
});

test('the MEAL manager reopens an approved plan as a draft', function () {
    writeFullPlan($this->mealManager)->call('submit');
    strategicPlanPage($this->programManager)->call('approve');

    strategicPlanPage($this->mealManager)->call('reopen')->assertHasNoErrors();

    expect(StrategicPlan::query()->sole()->status)->toBe(StrategicPlanStatus::Draft);
});

test('a plan is not sent without goals for every year and a linked indicator', function () {
    strategicPlanPage($this->mealManager)
        ->set('goals.2026', 'Reach the first 500 families.')
        ->call('submit')
        ->assertHasErrors(['plan' => __('strategic_plans.every_year_needs_goals')]);

    strategicPlanPage($this->mealManager)
        ->set('goals.2026', 'Reach the first 500 families.')
        ->set('goals.2027', 'Reach 1,200 families in all.')
        ->call('submit')
        ->assertHasErrors(['plan' => __('strategic_plans.link_an_indicator')]);

    expect(StrategicPlan::query()->sole()->status)->toBe(StrategicPlanStatus::Draft);
});

test('a percentage figure above 100 or a negative number is refused', function () {
    $share = Indicator::factory()->create(['result_id' => $this->outcome->id, 'name' => 'Share satisfied', 'measure' => IndicatorMeasure::Percentage]);

    strategicPlanPage($this->mealManager)
        ->set('indicatorToAdd', (string) $share->id)
        ->call('addIndicator')
        ->set('indicatorToAdd', (string) $this->indicator->id)
        ->call('addIndicator')
        ->set("targets.{$share->id}.2026", '120')
        ->set("targets.{$this->indicator->id}.2026", '-4')
        ->call('save')
        ->assertHasErrors(["targets.{$share->id}.2026", "targets.{$this->indicator->id}.2026"]);

    expect(StrategicPlan::query()->exists())->toBeFalse();
});

test('only the program framework indicators can be linked', function () {
    $proposalIndicator = Indicator::factory()->create([
        'result_id' => Result::factory()->type(ResultType::Outcome)->create(['program_id' => $this->program->id, 'proposal_id' => Proposal::factory()->create()->id])->id,
    ]);
    $otherProgramIndicator = Indicator::factory()->create(['result_id' => Result::factory()->type(ResultType::Outcome)->create()->id]);

    strategicPlanPage($this->mealManager)
        ->assertDontSee($otherProgramIndicator->name)
        ->set('indicatorToAdd', (string) $proposalIndicator->id)
        ->call('addIndicator')
        ->assertSet('linkedIndicators', [])
        ->set('linkedIndicators', [$otherProgramIndicator->id])
        ->call('save')
        ->assertHasErrors(['indicators']);
});

test('a program outside the strategy has no plan page', function () {
    $outsider = Program::factory()->create();

    $this->actingAs($this->mealManager)
        ->get(route('strategic-plans.show', [$this->strategy, $outsider]))
        ->assertNotFound();
});

test('the strategy page links each program to its strategic plan with its status', function () {
    StrategicPlan::factory()->submitted()->create(['strategy_id' => $this->strategy->id, 'program_id' => $this->program->id]);

    Livewire::actingAs($this->mealManager)
        ->test('pages::strategies.show', ['strategy' => $this->strategy])
        ->assertSeeHtml('data-test="strategic-plan-link-'.$this->program->id.'"')
        ->assertSee(StrategicPlanStatus::Submitted->label());
});
