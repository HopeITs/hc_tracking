<?php

use App\Models\AnnualPlan;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Create a program a plan can be written against.
 */
function crudProgram(string $name): Program
{
    return Program::factory()->create([
        'name' => $name,
        'strategy_id' => Strategy::factory(),
    ]);
}

test('a plan is created from the matrix', function () {
    $user = User::factory()->create();
    $program = crudProgram('Water Security');
    $manager = insertHrEmployee(firstNameEn: 'Layla', lastNameEn: 'Haddad');

    Livewire::actingAs($user)
        ->test('pages::annual-plans.index')
        ->call('openCreateModal')
        ->set('planProgramId', (string) $program->id)
        ->set('planManagerId', (string) $manager)
        ->set('planYear', '2027')
        ->call('savePlan')
        ->assertHasNoErrors()
        ->assertSet('showPlanModal', false);

    expect(AnnualPlan::query()->where('program_id', $program->id)->where('year', 2027)->first())
        ->not->toBeNull()
        ->manager_id->toBe($manager);
});

test('clicking an empty cell carries its program and fiscal year into the form', function () {
    $user = User::factory()->create();
    $water = crudProgram('Water Security');
    $shelter = crudProgram('Safe Shelter');

    AnnualPlan::factory()->create(['program_id' => $shelter->id, 'year' => 2025]);

    Livewire::actingAs($user)
        ->test('pages::annual-plans.index')
        ->call('openCreateModal', $water->id, 2025)
        ->assertSet('showPlanModal', true)
        ->assertSet('planProgramId', (string) $water->id)
        ->assertSet('planYear', '2025');
});

test('a program cannot hold two plans for the same fiscal year', function () {
    $user = User::factory()->create();
    $program = crudProgram('Water Security');
    $manager = insertHrEmployee();

    AnnualPlan::factory()->create(['program_id' => $program->id, 'year' => 2025]);

    Livewire::actingAs($user)
        ->test('pages::annual-plans.index')
        ->call('openCreateModal')
        ->set('planProgramId', (string) $program->id)
        ->set('planManagerId', (string) $manager)
        ->set('planYear', '2025')
        ->call('savePlan')
        ->assertHasErrors(['planYear' => 'unique']);

    expect(AnnualPlan::query()->where('program_id', $program->id)->count())->toBe(1);
});

test('a plan keeps its own year when it is edited', function () {
    $user = User::factory()->create();
    $program = crudProgram('Water Security');
    $plan = AnnualPlan::factory()->create(['program_id' => $program->id, 'year' => 2025]);
    $replacement = insertHrEmployee(firstNameEn: 'Nour', lastNameEn: 'Saleh');

    Livewire::actingAs($user)
        ->test('pages::annual-plans.index')
        ->call('openEditModal', $plan->id)
        ->assertSet('planYear', '2025')
        ->set('planManagerId', (string) $replacement)
        ->call('savePlan')
        ->assertHasNoErrors();

    expect($plan->fresh())
        ->year->toBe(2025)
        ->manager_id->toBe($replacement);
});

test('the program and the manager are both required', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::annual-plans.index')
        ->call('openCreateModal')
        ->set('planYear', '2027')
        ->call('savePlan')
        ->assertHasErrors(['planProgramId' => 'required', 'planManagerId' => 'required']);
});

test('deleting a plan from the matrix takes the targets committed in it', function () {
    $user = User::factory()->create();
    $program = crudProgram('Water Security');
    $plan = AnnualPlan::factory()->create(['program_id' => $program->id, 'year' => 2025]);

    $target = PlanIndicator::factory()->forAnnualPlan($plan)->create();

    Livewire::actingAs($user)
        ->test('pages::annual-plans.index')
        ->call('confirmDelete', $plan->id)
        ->assertSet('deletingPlanId', $plan->id)
        // The dialog is bound with wire:model, so the flag it reads has to be
        // raised alongside the id: an :open expression is evaluated only on the
        // dialog's first render and would leave the confirmation shut.
        ->assertSet('showDeleteModal', true)
        ->call('deletePlan')
        ->assertSet('deletingPlanId', null)
        ->assertSet('showDeleteModal', false);

    expect(AnnualPlan::query()->find($plan->id))->toBeNull()
        ->and(PlanIndicator::query()->find($target->id))->toBeNull();
});

test('dismissing the delete confirmation leaves the plan alone', function () {
    $user = User::factory()->create();
    $program = crudProgram('Water Security');
    $plan = AnnualPlan::factory()->create(['program_id' => $program->id, 'year' => 2025]);

    Livewire::actingAs($user)
        ->test('pages::annual-plans.index')
        ->call('confirmDelete', $plan->id)
        ->call('cancelDelete')
        ->assertSet('deletingPlanId', null)
        ->assertSet('showDeleteModal', false);

    expect(AnnualPlan::query()->find($plan->id))->not->toBeNull();
});

test('the matrix redraws itself after a write in the same request', function () {
    $user = User::factory()->create();
    $program = crudProgram('Water Security');
    $manager = insertHrEmployee(firstNameEn: 'Layla', lastNameEn: 'Haddad');

    AnnualPlan::factory()->create(['program_id' => $program->id, 'year' => 2025]);
    AnnualPlan::factory()->create(['program_id' => $program->id, 'year' => 2027]);

    Livewire::actingAs($user)
        ->test('pages::annual-plans.index')
        ->assertSee('Water Security, 2026, no plan')
        ->call('openCreateModal', $program->id, 2026)
        ->set('planManagerId', (string) $manager)
        ->call('savePlan')
        ->assertHasNoErrors()
        ->assertDontSee('Water Security, 2026, no plan');
});

test('the fiscal year list reaches next year without a plan existing for it', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::annual-plans.index')
        ->call('openCreateModal')
        ->assertSee((string) (now()->year + 1));
});

test('the plan detail page edits the manager and the fiscal year', function () {
    $user = User::factory()->create();
    $program = crudProgram('Water Security');
    $plan = AnnualPlan::factory()->create(['program_id' => $program->id, 'year' => 2025]);
    $replacement = insertHrEmployee(firstNameEn: 'Nour', lastNameEn: 'Saleh');

    Livewire::actingAs($user)
        ->test('pages::annual-plans.show', ['annualPlan' => $plan])
        ->call('openEditModal')
        ->assertSet('planYear', '2025')
        ->set('planYear', '2026')
        ->set('planManagerId', (string) $replacement)
        ->call('savePlan')
        ->assertHasNoErrors();

    expect($plan->fresh())
        ->year->toBe(2026)
        ->manager_id->toBe($replacement);
});

test('the plan detail page refuses a fiscal year the program already covers', function () {
    $user = User::factory()->create();
    $program = crudProgram('Water Security');
    $plan = AnnualPlan::factory()->create(['program_id' => $program->id, 'year' => 2025]);
    AnnualPlan::factory()->create(['program_id' => $program->id, 'year' => 2026]);

    Livewire::actingAs($user)
        ->test('pages::annual-plans.show', ['annualPlan' => $plan])
        ->call('openEditModal')
        ->set('planYear', '2026')
        ->call('savePlan')
        ->assertHasErrors(['planYear' => 'unique']);

    expect($plan->fresh()->year)->toBe(2025);
});

test('deleting a plan from its detail page returns to the matrix', function () {
    $user = User::factory()->create();
    $program = crudProgram('Water Security');
    $plan = AnnualPlan::factory()->create(['program_id' => $program->id, 'year' => 2025]);

    Livewire::actingAs($user)
        ->test('pages::annual-plans.show', ['annualPlan' => $plan])
        ->call('deletePlan')
        ->assertRedirect(route('annual-plans.index'));

    expect(AnnualPlan::query()->find($plan->id))->toBeNull();
});

test('the create and delete controls cost the matrix no extra read of annual_plans', function () {
    $user = User::factory()->create();

    foreach (['Water Security', 'Safe Shelter'] as $name) {
        $program = crudProgram($name);

        foreach (range(2023, 2025) as $year) {
            AnnualPlan::factory()->create(['program_id' => $program->id, 'year' => $year]);
        }
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    Livewire::actingAs($user)->test('pages::annual-plans.index');

    $planQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains($query['query'], 'annual_plans'));

    DB::disableQueryLog();

    // The form reads the memoised year options rather than the table, and the
    // delete confirmation only loads its plan once one is pending, so the four
    // reads that bind the matrix are untouched.
    expect($planQueries)->toHaveCount(4);
});

test('editing a plan keeps the reader on the page of programs they were reading', function () {
    $user = User::factory()->create();

    foreach (range(1, 20) as $index) {
        crudProgram(sprintf('Program %02d', $index));
    }

    $program = Program::query()->get()->sole(fn (Program $program): bool => $program->name === 'Program 18');
    $plan = AnnualPlan::factory()->create(['program_id' => $program->id, 'year' => 2025]);
    $replacement = insertHrEmployee(firstNameEn: 'Nour', lastNameEn: 'Saleh');

    Livewire::actingAs($user)
        ->test('pages::annual-plans.index')
        ->set('paginators.page', 2)
        ->call('openEditModal', $plan->id)
        ->set('planManagerId', (string) $replacement)
        ->call('savePlan')
        ->assertHasNoErrors()
        ->assertSet('paginators.page', 2);
});

test('a plan written outside the visible span opens the year window onto it', function () {
    $user = User::factory()->create();
    $program = crudProgram('Water Security');
    $manager = insertHrEmployee();

    $anchor = crudProgram('Safe Shelter');
    AnnualPlan::factory()->create(['program_id' => $anchor->id, 'year' => 2023]);
    AnnualPlan::factory()->create(['program_id' => $anchor->id, 'year' => 2025]);

    Livewire::actingAs($user)
        ->test('pages::annual-plans.index')
        ->call('toggleYear', 2023)
        ->call('openCreateModal')
        ->set('planProgramId', (string) $program->id)
        ->set('planManagerId', (string) $manager)
        ->set('planYear', '2025')
        ->call('savePlan')
        ->assertHasNoErrors()
        // The filters had the grid pinned to 2023; the write reopens the window
        // and points the cockpit at the year just planned.
        ->assertSet('years', [])
        ->assertSet('focusYear', '2025');
});

test('a plan written inside the visible span leaves the filters untouched', function () {
    $user = User::factory()->create();
    $program = crudProgram('Water Security');
    $other = crudProgram('Safe Shelter');
    $manager = insertHrEmployee();

    AnnualPlan::factory()->create(['program_id' => $program->id, 'year' => 2025]);

    Livewire::actingAs($user)
        ->test('pages::annual-plans.index')
        ->call('toggleYear', 2025)
        ->call('openCreateModal', $other->id, 2025)
        ->set('planManagerId', (string) $manager)
        ->call('savePlan')
        ->assertHasNoErrors()
        ->assertSet('years', [2025]);
});
