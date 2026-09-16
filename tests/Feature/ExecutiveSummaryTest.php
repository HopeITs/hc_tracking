<?php

use App\Enums\UserRole;
use App\Models\AnnualPlan;
use App\Models\Indicator;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Result;
use App\Models\User;
use Livewire\Livewire;

/**
 * The fiscal year the summary defaults to when the picker is untouched.
 */
function currentFiscalYear(): int
{
    return (int) now()->year;
}

test('guests are redirected from the executive summary', function () {
    $this->get(route('executive-summary.index'))->assertRedirect(route('login'));
});

test('all authenticated roles can access the executive summary while permissions are deferred', function (UserRole $role) {
    $user = User::factory()->role($role)->create();

    $this->actingAs($user)
        ->get(route('executive-summary.index'))
        ->assertOk()
        ->assertSeeText('Executive Summary');
})->with([
    'officer' => UserRole::Officer,
    'manager' => UserRole::Manager,
    'meal' => UserRole::Meal,
    'admin' => UserRole::Admin,
]);

test('annual plan coverage counts the programs holding a plan for the selected year', function () {
    $user = User::factory()->create();
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
    $user = User::factory()->create();
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
    $user = User::factory()->create();
    $result = Result::factory()->create();
    $indicators = Indicator::factory(2)->create(['result_id' => $result->id]);

    PlanIndicator::factory()->forProposal()->create(['indicator_id' => $indicators[0]->id]);

    Livewire::actingAs($user)
        ->test('pages::executive-summary.index')
        ->assertSee('0 of 2 indicators');
});

test('projects carrying a target counts the projects named by this year plans', function () {
    $user = User::factory()->create();
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
    $user = User::factory()->create();
    $program = Program::factory()->create();
    $cities = [insertHrCity('Aleppo'), insertHrCity('Homs'), insertHrCity('Hama')];

    Project::factory()->create(['city_id' => $cities[0], 'program_id' => $program->id]);

    Livewire::actingAs($user)
        ->test('pages::executive-summary.index')
        ->assertSee('1 of 3 cities')
        ->assertSee('Portfolio');
});

test('changing the fiscal year moves the year scoped cards but leaves geographic reach alone', function () {
    $user = User::factory()->create();
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
    $user = User::factory()->create();
    Program::factory(2)->create();

    Livewire::actingAs($user)
        ->test('pages::executive-summary.index')
        ->assertSee('Annual plan '.currentFiscalYear())
        ->assertSee('0 of 2 programs')
        ->assertSee('0%');
});

test('a fiscal year outside the offered options falls back to the current one', function () {
    $user = User::factory()->create();
    $program = Program::factory()->create();

    AnnualPlan::factory()->create(['program_id' => $program->id, 'year' => currentFiscalYear()]);

    Livewire::actingAs($user)
        ->test('pages::executive-summary.index')
        ->set('year', '1999')
        ->assertSee('Annual plan '.currentFiscalYear())
        ->assertSee('1 of 1 programs');
});

test('an empty portfolio renders a dash instead of dividing by zero', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::executive-summary.index')
        ->assertSee('—')
        ->assertSee('0 of 0 programs')
        ->assertSee('0 of 0 indicators')
        ->assertSee('0 of 0 projects')
        ->assertSee('0 of 0 cities');
});

test('a partial share never rounds up to full coverage', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test('pages::executive-summary.index')->instance();

    expect($component->percentageOf(199, 200))->toBe(99)
        ->and($component->percentageOf(200, 200))->toBe(100)
        ->and($component->percentageOf(1, 3))->toBe(33)
        ->and($component->percentageOf(0, 4))->toBe(0)
        ->and($component->percentageOf(0, 0))->toBeNull();
});
