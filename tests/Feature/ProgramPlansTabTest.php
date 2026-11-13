<?php

use App\Models\AnnualPlan;
use App\Models\Indicator;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Report;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->travelTo('2026-07-02 09:00:00');
});

/**
 * A 2026 plan with three targets on one project: one reached, one with a
 * late draft report, one with a report waiting for approval.
 *
 * @return array{program: Program, plan: AnnualPlan, reached: PlanIndicator, late: PlanIndicator, review: PlanIndicator}
 */
function programWithPlanTargets(): array
{
    $program = Program::factory()->create();
    $project = Project::factory()->create(['program_id' => $program->id, 'name' => 'Clean Water Daraa']);
    $plan = AnnualPlan::factory()->create(['program_id' => $program->id, 'year' => 2026]);
    $target = fn (string $name, float $goal): PlanIndicator => PlanIndicator::factory()->create([
        'source_id' => $plan->id,
        'project_id' => $project->id,
        'indicator_id' => Indicator::factory()->create(['name' => $name])->id,
        'target' => $goal,
    ]);

    $reached = $target('Wells repaired', 10);
    Report::factory()->approved()->create(['plan_indicator_id' => $reached->id, 'value' => 12, 'planned_date' => '2026-03-31']);

    $late = $target('Households reached', 400);
    Report::factory()->create(['plan_indicator_id' => $late->id, 'value' => 50, 'planned_date' => '2026-06-15']);

    $review = $target('Committees trained', 8);
    Report::factory()->reviewed()->create(['plan_indicator_id' => $review->id, 'value' => 2, 'planned_date' => '2026-06-30']);

    return compact('program', 'plan', 'reached', 'late', 'review');
}

test('the plans tab opens on the current year and counts its targets by state', function () {
    ['program' => $program] = programWithPlanTargets();
    AnnualPlan::factory()->create(['program_id' => $program->id, 'year' => 2025]);

    $component = Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'plans')
        ->assertSee('Annual plan 2026')
        ->assertSee('Clean Water Daraa')
        ->assertSee('Households reached');

    $board = $component->instance()->planBoard;

    expect($component->instance()->shownPlanYear)->toBe(2026)
        ->and($board['counts'])->toMatchArray(['all' => 3, 'reached' => 1, 'late' => 1, 'review' => 1])
        ->and($board['groups'])->toHaveCount(1)
        ->and($board['groups'][0])->toMatchArray(['label' => 'Clean Water Daraa', 'count' => 3, 'late' => 1, 'reached' => 1]);
});

test('each group card opens its own targets in a modal', function () {
    ['program' => $program, 'late' => $late] = programWithPlanTargets();
    $otherProject = Project::factory()->create(['program_id' => $program->id]);
    $elsewhere = PlanIndicator::factory()->create([
        'source_id' => $late->source_id,
        'project_id' => $otherProject->id,
        'indicator_id' => Indicator::factory()->create(['name' => 'Latrines built'])->id,
    ]);

    $html = Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'plans')
        ->assertSee('Show 3 targets')
        ->assertSee("\$flux.modal('plan-targets-project-{$late->project_id}').show()", false)
        ->html();

    // Each target sits only in its own card's modal.
    $modal = fn (int $projectId): string => str($html)->after('data-plan-targets-modal="project-'.$projectId.'"')->before('</ul>')->toString();

    expect($modal($late->project_id))->toContain('data-plan-target="'.$late->id.'"')->not->toContain('data-plan-target="'.$elsewhere->id.'"')
        ->and($modal($otherProject->id))->toContain('data-plan-target="'.$elsewhere->id.'"');
});

test('the year strip offers only last year, this year and next year', function () {
    ['program' => $program] = programWithPlanTargets();
    AnnualPlan::factory()->create(['program_id' => $program->id, 'year' => 2023]);

    $component = Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'plans')
        ->assertDontSee('data-plan-year="2023"', false);

    expect(array_column($component->instance()->planStripYears, 'year'))->toBe([2025, 2026, 2027]);

    // A year outside the strip (an old link) falls back to this year.
    $component->set('planYear', 2023);

    expect($component->instance()->shownPlanYear)->toBe(2026);
});

test('a filter card narrows the targets and clicking it again shows them all', function () {
    ['program' => $program] = programWithPlanTargets();

    $component = Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'plans')
        ->call('pickPlanFilter', 'late');

    expect($component->instance()->planBoard['groups'][0]['rows']->pluck('name')->all())->toBe(['Households reached']);

    $component->call('pickPlanFilter', 'late');

    expect($component->get('planFilter'))->toBe('all')
        ->and($component->instance()->planBoard['shown'])->toBe(3);
});

test('grouping by reporting puts each target under its most urgent report state', function () {
    ['program' => $program] = programWithPlanTargets();

    $groups = Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'plans')
        ->set('planGroupBy', 'status')
        ->instance()
        ->planBoard['groups'];

    expect($groups->mapWithKeys(fn (array $group): array => [$group['key'] => $group['rows']->pluck('name')->all()])->all())->toBe([
        'late' => ['Households reached'],
        'review' => ['Committees trained'],
        'approved' => ['Wells repaired'],
    ]);
});

test('a target trailing the share of the year gone counts as behind pace', function () {
    ['program' => $program] = programWithPlanTargets();

    // On 2 July half the year has gone: 50 of 400 (12.5%) and 2 of 8 (25%) trail it, 12 of 10 is reached.
    $rows = Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'plans')
        ->instance()
        ->planTargetRows;

    expect($rows->where('behind', true)->pluck('name')->sort()->values()->all())->toBe(['Committees trained', 'Households reached']);
});

test('picking a year with no plan offers to write that year\'s plan', function () {
    ['program' => $program] = programWithPlanTargets();
    $program->strategy->update(['start_year' => 2025, 'end_year' => 2027]);

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'plans')
        ->call('pickPlanYear', 2027)
        ->assertSee('No plan for 2027 yet')
        ->assertSee(route('annual-plans.targets', ['create' => 1, 'program' => $program->id, 'year' => 2027]));
});

test('a viewer who may not see targets gets the plan without its targets', function () {
    ['program' => $program] = programWithPlanTargets();
    $viewer = User::factory()->create();
    $viewer->givePermissionTo(Permission::findOrCreate('programs.view', 'web'));

    $component = Livewire::actingAs($viewer)
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'plans')
        ->assertSee('Annual plan 2026')
        ->assertDontSee('Households reached');

    expect($component->instance()->planBoard)->toBeNull();
});

test('the annual plans page opens the new plan form filled in from a program tab link', function () {
    $manager = insertHrEmployee();
    $program = Program::factory()->create(['manager_id' => $manager]);

    Livewire::withQueryParams(['create' => 1, 'program' => $program->id, 'year' => 2027])
        ->actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::annual-plans.index')
        ->assertSet('showPlanModal', true)
        ->assertSet('planProgramId', (string) $program->id)
        ->assertSet('planYear', '2027')
        ->assertSet('planManagerId', (string) $manager);
});

test('the new plan link does not open the form for someone who may not create plans', function () {
    $program = Program::factory()->create();
    $viewer = User::factory()->create();
    $viewer->givePermissionTo(Permission::findOrCreate('annual-plans.view', 'web'));

    Livewire::withQueryParams(['create' => 1, 'program' => $program->id, 'year' => 2027])
        ->actingAs($viewer)
        ->test('pages::annual-plans.index')
        ->assertSet('showPlanModal', false);
});
