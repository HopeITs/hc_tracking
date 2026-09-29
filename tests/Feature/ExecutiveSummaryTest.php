<?php

use App\Enums\Currency;
use App\Enums\IndicatorMeasure;
use App\Enums\MilestoneStatus;
use App\Models\Account;
use App\Models\AnnualPlan;
use App\Models\DonorProposal;
use App\Models\Indicator;
use App\Models\Milestone;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Report;
use App\Models\Result;
use App\Models\TransfersTracking;
use App\Models\User;
use Livewire\Livewire;

use function Livewire\invade;

/**
 * The fiscal year the summary defaults to when the picker is untouched.
 */
function currentFiscalYear(): int
{
    return (int) now()->year;
}

/**
 * A numeric target committed in an annual plan, at the given target value.
 */
function commitTarget(AnnualPlan $plan, Indicator $indicator, string $target = '100.00'): PlanIndicator
{
    return PlanIndicator::factory()->forAnnualPlan($plan)->create([
        'indicator_id' => $indicator->id,
        'target' => $target,
    ]);
}

test('guests are redirected from the executive summary', function () {
    $this->get(route('executive-summary.index'))->assertRedirect(route('login'));
});

test('only the money-facing roles can access the executive summary', function (string $roleName) {
    $user = User::factory()->role($roleName)->create();

    $this->actingAs($user)
        ->get(route('executive-summary.index'))
        ->assertOk()
        ->assertSeeText('Executive Summary');
})->with([
    'financial' => 'Financial',
    'general manager' => 'General Manager',
    'admin' => 'Super Admin',
]);

test('every other role is refused the executive summary', function (string $roleName) {
    $user = User::factory()->role($roleName)->create();

    $this->actingAs($user)
        ->get(route('executive-summary.index'))
        ->assertForbidden();
})->with([
    'team member' => 'Team Member',
    'media team' => 'Media Team',
    'public relations' => 'Public Relations',
    'public relations manager' => 'Public Relations Manager',
    'manager' => 'Managers',
    'meal' => 'MEAL',
]);

test('the fiscal year picker shows the year the page is actually summarising', function () {
    $user = User::factory()->role('General Manager')->create();

    // A later year exists, so it heads the picker's option list. The page still
    // summarises the current year until the picker is touched, and the trigger
    // must say so rather than showing the first option.
    AnnualPlan::factory()->create(['year' => currentFiscalYear() + 1]);
    AnnualPlan::factory()->create(['year' => currentFiscalYear()]);

    $component = Livewire::actingAs($user)->test('pages::executive-summary.index');

    expect($component->instance()->fiscalYear)->toBe(currentFiscalYear());

    $component->assertSeeHtml('<span class="truncate">'.currentFiscalYear().'</span>');
});

test('annual plan coverage counts the programs holding a plan for the selected year', function () {
    $user = User::factory()->role('General Manager')->create();
    $programs = Program::factory(4)->create();

    foreach ($programs->take(3) as $program) {
        AnnualPlan::factory()->create(['program_id' => $program->id, 'year' => currentFiscalYear()]);
    }

    Livewire::actingAs($user)
        ->test('pages::executive-summary.index')
        ->assertSee('3 of 4 programs')
        ->assertSee('75%');
});

test('indicators targeted counts only the indicators committed in an annual plan of the year', function () {
    $user = User::factory()->role('General Manager')->create();
    $result = Result::factory()->create();
    $indicators = Indicator::factory(4)->create(['result_id' => $result->id]);
    $plan = AnnualPlan::factory()->create(['year' => currentFiscalYear()]);
    $earlierPlan = AnnualPlan::factory()->create(['year' => currentFiscalYear() - 1]);

    PlanIndicator::factory()->forAnnualPlan($plan)->create(['indicator_id' => $indicators[0]->id]);
    PlanIndicator::factory()->forAnnualPlan($plan)->create(['indicator_id' => $indicators[1]->id]);
    PlanIndicator::factory()->forAnnualPlan($earlierPlan)->create(['indicator_id' => $indicators[2]->id]);

    Livewire::actingAs($user)
        ->test('pages::executive-summary.index')
        ->assertSee('2 of 4 indicators')
        ->assertSee('50%');
});

test('a target committed in a donor proposal is not counted as annual plan coverage', function () {
    $user = User::factory()->role('General Manager')->create();
    $result = Result::factory()->create();
    $indicators = Indicator::factory(2)->create(['result_id' => $result->id]);

    PlanIndicator::factory()->forProposal()->create(['indicator_id' => $indicators[0]->id]);

    Livewire::actingAs($user)
        ->test('pages::executive-summary.index')
        ->assertSee('0 of 2 indicators');
});

test('projects carrying a target counts the projects named by this year plans', function () {
    $user = User::factory()->role('General Manager')->create();
    $program = Program::factory()->create();
    $projects = Project::factory(4)->create(['program_id' => $program->id]);
    $plan = AnnualPlan::factory()->create(['program_id' => $program->id, 'year' => currentFiscalYear()]);

    PlanIndicator::factory()->forAnnualPlan($plan)->create(['project_id' => $projects[0]->id]);

    Livewire::actingAs($user)
        ->test('pages::executive-summary.index')
        ->assertSee('1 of 4 projects')
        ->assertSee('25%');
});

test('geographic reach counts the cities holding a project across the whole portfolio', function () {
    $user = User::factory()->role('General Manager')->create();
    $program = Program::factory()->create();
    $cities = [insertHrCity('Aleppo'), insertHrCity('Homs'), insertHrCity('Hama')];

    Project::factory()->create(['city_id' => $cities[0], 'program_id' => $program->id]);

    Livewire::actingAs($user)
        ->test('pages::executive-summary.index')
        ->assertSee('1 of 3 cities')
        ->assertSee('Portfolio');
});

test('changing the fiscal year moves the year scoped measures but leaves geographic reach alone', function () {
    $user = User::factory()->role('General Manager')->create();
    $programs = Program::factory(3)->create();
    $cities = [insertHrCity('Aleppo'), insertHrCity('Homs')];

    AnnualPlan::factory()->create(['program_id' => $programs[0]->id, 'year' => currentFiscalYear()]);
    AnnualPlan::factory()->create(['program_id' => $programs[1]->id, 'year' => currentFiscalYear()]);
    AnnualPlan::factory()->create(['program_id' => $programs[2]->id, 'year' => currentFiscalYear() - 1]);
    Project::factory()->create(['city_id' => $cities[0], 'program_id' => $programs[0]->id]);

    Livewire::actingAs($user)
        ->test('pages::executive-summary.index')
        ->assertSee('2 of 3 programs')
        ->assertSee('Annual plan '.currentFiscalYear())
        ->assertSee('1 of 2 cities')
        ->set('year', (string) (currentFiscalYear() - 1))
        ->assertSee('1 of 3 programs')
        ->assertSee('Annual plan '.(currentFiscalYear() - 1))
        ->assertSee('1 of 2 cities');
});

test('a fiscal year with no plans reports no coverage rather than disappearing', function () {
    $user = User::factory()->role('General Manager')->create();
    Program::factory(2)->create();

    Livewire::actingAs($user)
        ->test('pages::executive-summary.index')
        ->assertSee('Annual plan '.currentFiscalYear())
        ->assertSee('0 of 2 programs')
        ->assertSee('0%');
});

test('a fiscal year outside the offered options falls back to the current one', function () {
    $user = User::factory()->role('General Manager')->create();
    $program = Program::factory()->create();

    AnnualPlan::factory()->create(['program_id' => $program->id, 'year' => currentFiscalYear()]);

    Livewire::actingAs($user)
        ->test('pages::executive-summary.index')
        ->set('year', '1999')
        ->assertSee('Annual plan '.currentFiscalYear())
        ->assertSee('1 of 1 programs');
});

test('an empty portfolio renders a dash instead of dividing by zero', function () {
    $user = User::factory()->role('General Manager')->create();

    Livewire::actingAs($user)
        ->test('pages::executive-summary.index')
        ->assertSee('—')
        ->assertSee('0 of 0 programs')
        ->assertSee('0 of 0 indicators')
        ->assertSee('0 of 0 projects')
        ->assertSee('0 of 0 cities');
});

test('a partial share never rounds up to full coverage', function () {
    $user = User::factory()->role('General Manager')->create();

    $component = invade(Livewire::actingAs($user)->test('pages::executive-summary.index')->instance());

    expect($component->percentageOf(199, 200))->toBe(99)
        ->and($component->percentageOf(200, 200))->toBe(100)
        ->and($component->percentageOf(1, 3))->toBe(33)
        ->and($component->percentageOf(0, 4))->toBe(0)
        ->and($component->percentageOf(0, 0))->toBeNull();
});

test('a partial share of an amount never rounds up to fully landed', function () {
    $user = User::factory()->role('General Manager')->create();

    $component = invade(Livewire::actingAs($user)->test('pages::executive-summary.index')->instance());

    expect($component->shareOfAmount(9999.99, 10000.0))->toBe(99)
        ->and($component->shareOfAmount(10000.0, 10000.0))->toBe(100)
        ->and($component->shareOfAmount(10500.0, 10000.0))->toBe(100)
        ->and($component->shareOfAmount(0.0, 10000.0))->toBe(0)
        ->and($component->shareOfAmount(0.0, 0.0))->toBeNull();
});

test('the targets row pairs what the year committed with what it reached', function () {
    $user = User::factory()->role('General Manager')->create();
    $result = Result::factory()->create();
    $indicators = Indicator::factory(3)->create([
        'result_id' => $result->id,
        'measure' => IndicatorMeasure::Numeric,
    ]);
    $plan = AnnualPlan::factory()->create(['year' => currentFiscalYear()]);

    $met = commitTarget($plan, $indicators[0]);
    $partial = commitTarget($plan, $indicators[1]);
    commitTarget($plan, $indicators[2]);

    Report::factory()->approved()->create([
        'plan_indicator_id' => $met->id,
        'value' => '100.00',
        'planned_date' => currentFiscalYear().'-03-31',
    ]);
    Report::factory()->approved()->create([
        'plan_indicator_id' => $partial->id,
        'value' => '40.00',
        'planned_date' => currentFiscalYear().'-03-31',
    ]);

    $component = Livewire::actingAs($user)->test('pages::executive-summary.index');
    $row = collect($component->instance()->ledger)->firstWhere('key', 'targets');

    expect($row['committed'])->toBe(3)
        ->and($row['delivered'])->toBe(1)
        ->and($row['gap'])->toBe(2)
        ->and($row['share'])->toBe(33);

    $component
        ->assertSee('Targets committed')
        ->assertSee('Targets reached')
        ->assertSee('1 in progress · 1 not started');
});

test('an unapproved report does not count as a target reached', function () {
    $user = User::factory()->role('General Manager')->create();
    $result = Result::factory()->create();
    $indicator = Indicator::factory()->create([
        'result_id' => $result->id,
        'measure' => IndicatorMeasure::Numeric,
    ]);
    $plan = AnnualPlan::factory()->create(['year' => currentFiscalYear()]);
    $target = commitTarget($plan, $indicator);

    Report::factory()->reviewed()->create([
        'plan_indicator_id' => $target->id,
        'value' => '100.00',
        'planned_date' => currentFiscalYear().'-03-31',
    ]);

    $row = collect(
        Livewire::actingAs($user)->test('pages::executive-summary.index')->instance()->ledger
    )->firstWhere('key', 'targets');

    expect($row['committed'])->toBe(1)->and($row['delivered'])->toBe(0);
});

test('money is one ledger row per currency and the currencies are never summed', function () {
    $user = User::factory()->role('General Manager')->create();
    $account = Account::factory()->create();
    $dollars = DonorProposal::factory()->create(['currency' => Currency::Usd]);
    $euros = DonorProposal::factory()->create(['currency' => Currency::Eur]);

    TransfersTracking::factory()->create([
        'donor_proposal_id' => $dollars->id,
        'payment_amount' => '1000.00',
        'amount_transfered_currency' => Currency::Usd,
        'scheduled_date' => currentFiscalYear().'-02-01',
    ]);

    TransfersTracking::factory()->received($account)->create([
        'donor_proposal_id' => $euros->id,
        'payment_amount' => '500.00',
        'amount_received' => '500.00',
        'amount_transfered_currency' => Currency::Eur,
        'amount_received_currency' => Currency::Eur,
        'scheduled_date' => currentFiscalYear().'-03-01',
    ]);

    $rows = collect(
        Livewire::actingAs($user)->test('pages::executive-summary.index')->instance()->ledger
    )->where('kind', 'money')->values();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('currency')->all())->toBe(['USD', 'EUR'])
        // Nothing landed against the dollar tranche, so its whole promise is the gap.
        ->and($rows[0]['committed'])->toBe(1000.0)
        ->and($rows[0]['delivered'])->toBe(0.0)
        ->and($rows[0]['share'])->toBe(0)
        // The euro tranche landed net of its one percent fee: 495 of 500.
        ->and($rows[1]['delivered'])->toBe(495.0)
        ->and($rows[1]['share'])->toBe(99);
});

test('a received tranche whose net amount nobody recorded counts as nothing landed', function () {
    $user = User::factory()->role('General Manager')->create();
    $account = Account::factory()->create();
    $agreement = DonorProposal::factory()->create(['currency' => Currency::Usd]);

    TransfersTracking::factory()->received($account)->create([
        'donor_proposal_id' => $agreement->id,
        'payment_amount' => '1000.00',
        'amount_transfered_currency' => Currency::Usd,
        'amount_received_currency' => Currency::Usd,
        'net_amount_received' => null,
        'scheduled_date' => currentFiscalYear().'-02-01',
    ]);

    $row = collect(
        Livewire::actingAs($user)->test('pages::executive-summary.index')->instance()->ledger
    )->firstWhere('key', 'money-USD');

    expect($row['delivered'])->toBe(0.0)->and($row['gap'])->toBe(1000.0);
});

test('milestones are dated by their end date and fall back to their start date', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->create();

    // No end date recorded: the start date is what dates it into the year.
    Milestone::factory()->reached()->create([
        'project_id' => $project->id,
        'planned_start_date' => currentFiscalYear().'-04-01',
    ]);

    // An end date is recorded and wins, even though the start is a year earlier.
    Milestone::factory()->create([
        'project_id' => $project->id,
        'planned_start_date' => (currentFiscalYear() - 1).'-11-01',
        'planned_end_date' => currentFiscalYear().'-06-30',
        'status' => MilestoneStatus::Planned,
    ]);

    // Cancelled work is not a broken promise, so it leaves the denominator.
    Milestone::factory()->create([
        'project_id' => $project->id,
        'planned_start_date' => currentFiscalYear().'-05-01',
        'status' => MilestoneStatus::Cancelled,
    ]);

    $row = collect(
        Livewire::actingAs($user)->test('pages::executive-summary.index')->instance()->ledger
    )->firstWhere('key', 'milestones');

    expect($row['committed'])->toBe(2)
        ->and($row['delivered'])->toBe(1)
        ->and($row['share'])->toBe(50);
});

test('a report is delivered only once it is approved', function () {
    $user = User::factory()->role('General Manager')->create();
    $plannedDate = currentFiscalYear().'-06-30';

    Report::factory()->approved()->create(['planned_date' => $plannedDate]);
    Report::factory()->reviewed()->create(['planned_date' => $plannedDate]);
    Report::factory()->create(['planned_date' => $plannedDate]);

    $component = Livewire::actingAs($user)->test('pages::executive-summary.index');
    $row = collect($component->instance()->ledger)->firstWhere('key', 'reports');

    expect($row['committed'])->toBe(3)
        ->and($row['delivered'])->toBe(1)
        ->and($row['gap'])->toBe(2);

    $component->assertSee('1 in review · 1 still draft');
});

test('a headline figure carries its movement against the prior year', function () {
    $user = User::factory()->role('General Manager')->create();
    $programs = Program::factory(2)->create();

    // Last year: one of two programs planned. This year: both.
    AnnualPlan::factory()->create(['program_id' => $programs[0]->id, 'year' => currentFiscalYear() - 1]);
    AnnualPlan::factory()->create(['program_id' => $programs[0]->id, 'year' => currentFiscalYear()]);
    AnnualPlan::factory()->create(['program_id' => $programs[1]->id, 'year' => currentFiscalYear()]);

    $component = Livewire::actingAs($user)->test('pages::executive-summary.index');
    $planned = collect($component->instance()->balance)->firstWhere('key', 'planned');

    expect($planned['share'])->toBe(100)->and($planned['delta'])->toBe(50);

    $component->assertSee('+50');
});

test('a year with nothing to compare against carries no delta', function () {
    $user = User::factory()->role('General Manager')->create();
    $program = Program::factory()->create();

    AnnualPlan::factory()->create(['program_id' => $program->id, 'year' => currentFiscalYear()]);

    $money = collect(
        Livewire::actingAs($user)->test('pages::executive-summary.index')->instance()->balance
    )->firstWhere('key', 'money');

    expect($money['share'])->toBeNull()->and($money['delta'])->toBeNull();
});

test('opening a ledger row decomposes its gap into the records that own it', function () {
    $user = User::factory()->role('General Manager')->create();
    $program = Program::factory()->create(['name' => 'Water and Sanitation']);
    $result = Result::factory()->create(['program_id' => $program->id]);
    $indicator = Indicator::factory()->create([
        'result_id' => $result->id,
        'measure' => IndicatorMeasure::Numeric,
    ]);
    $plan = AnnualPlan::factory()->create(['program_id' => $program->id, 'year' => currentFiscalYear()]);

    commitTarget($plan, $indicator);

    Livewire::actingAs($user)
        ->test('pages::executive-summary.index')
        ->assertDontSee('Widest gap first')
        ->call('toggleRow', 'targets')
        ->assertSet('openRow', 'targets')
        ->assertSee('Widest gap first')
        ->assertSee('Water and Sanitation')
        ->call('toggleRow', 'targets')
        ->assertSet('openRow', '')
        ->assertDontSee('Widest gap first');
});

test('the reach section states planned beneficiaries by group for the running projects', function () {
    $user = User::factory()->role('General Manager')->create();
    $program = Program::factory()->create();
    $city = insertHrCity('Aleppo');

    Project::factory()->create([
        'program_id' => $program->id,
        'city_id' => $city,
        'start_date' => currentFiscalYear().'-01-01',
        'end_date' => currentFiscalYear().'-12-31',
        'target_beneficiaries' => 1000,
        'target_men' => 100,
        'target_women' => 400,
        'target_boys' => 200,
        'target_girls' => 300,
    ]);

    // A project that closed before this year begins is not part of its reach.
    Project::factory()->create([
        'program_id' => $program->id,
        'city_id' => $city,
        'start_date' => (currentFiscalYear() - 3).'-01-01',
        'end_date' => (currentFiscalYear() - 2).'-12-31',
        'target_beneficiaries' => 9999,
        'target_men' => 9999,
        'target_women' => 0,
        'target_boys' => 0,
        'target_girls' => 0,
    ]);

    Livewire::actingAs($user)
        ->test('pages::executive-summary.index')
        ->assertSee('People this year sets out to reach')
        ->assertSee('1,000')
        ->assertSee('Women')
        ->assertSee('400')
        ->assertSee('Aleppo')
        ->assertDontSee('9,999');
});

test('a portfolio with no beneficiary targets says so rather than drawing an empty split', function () {
    $user = User::factory()->role('General Manager')->create();
    $program = Program::factory()->create();

    Project::factory()->withoutDemographics()->create([
        'program_id' => $program->id,
        'start_date' => currentFiscalYear().'-01-01',
        'end_date' => currentFiscalYear().'-12-31',
        'target_beneficiaries' => 0,
    ]);

    Livewire::actingAs($user)
        ->test('pages::executive-summary.index')
        ->assertSee('No beneficiary targets recorded');
});
