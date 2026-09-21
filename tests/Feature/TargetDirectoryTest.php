<?php

use App\Enums\IndicatorMeasure;
use App\Enums\Period;
use App\Models\AnnualPlan;
use App\Models\Donor;
use App\Models\Indicator;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Result;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('guests are redirected from the target directory', function () {
    $this->get(route('targets.index'))->assertRedirect(route('login'));
});

test('targets can be filtered by source type', function () {
    $user = User::factory()->create();
    $planIndicator = Indicator::factory()->create(['name' => 'Committed in a plan']);
    $proposalIndicator = Indicator::factory()->create(['name' => 'Committed in a proposal']);

    PlanIndicator::factory()->create([
        'source_type' => 'annual_plan',
        'source_id' => AnnualPlan::factory()->create()->id,
        'indicator_id' => $planIndicator->id,
    ]);
    PlanIndicator::factory()->create([
        'source_type' => 'proposal',
        'source_id' => Proposal::factory()->create()->id,
        'indicator_id' => $proposalIndicator->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::targets.index')
        ->assertSee('Committed in a plan')
        ->assertSee('Committed in a proposal')
        ->set('sourceType', 'annual_plan')
        ->assertSee('Committed in a plan')
        ->assertDontSee('Committed in a proposal')
        ->call('clearFilters')
        ->set('sourceType', 'proposal')
        ->assertDontSee('Committed in a plan')
        ->assertSee('Committed in a proposal');
});

test('a target stores the morph map key and never a class name', function () {
    $user = User::factory()->create();
    $plan = AnnualPlan::factory()->create();
    $indicator = Indicator::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::targets.index')
        ->call('openCreateModal')
        ->set('targetSourceType', 'annual_plan')
        ->set('targetSourceId', (string) $plan->id)
        ->set('targetIndicatorId', (string) $indicator->id)
        ->set('targetValue', '12000.00')
        ->set('targetPeriod', Period::Quarterly->value)
        ->call('saveTarget')
        ->assertHasNoErrors();

    $row = DB::table('plan_indicators')->where('indicator_id', $indicator->id)->sole();

    expect($row->source_type)->toBe('annual_plan')
        ->and($row->source_type)->not->toContain('App\\Models')
        ->and((int) $row->source_id)->toBe($plan->id);
});

test('the source type select offers exactly the two mapped sources', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::targets.index')
        ->call('openCreateModal')
        ->assertSee('Annual plan')
        ->assertSee('Proposal')
        ->assertDontSee('App\\Models', false);
});

test('changing the source type clears the chosen source', function () {
    $user = User::factory()->create();
    $plan = AnnualPlan::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::targets.index')
        ->call('openCreateModal')
        ->set('targetSourceType', 'annual_plan')
        ->set('targetSourceId', (string) $plan->id)
        ->set('targetSourceType', 'proposal')
        ->assertSet('targetSourceId', '');
});

test('a target whose source was removed renders instead of throwing', function () {
    $user = User::factory()->create();
    $plan = AnnualPlan::factory()->create();
    $indicator = Indicator::factory()->create(['name' => 'Orphaned target indicator']);

    PlanIndicator::factory()->create([
        'source_type' => 'annual_plan',
        'source_id' => $plan->id,
        'indicator_id' => $indicator->id,
    ]);

    // A query-builder delete fires no model events, so
    // PlanIndicatorSourceObserver never runs and the target is orphaned. No
    // foreign key can stop this; the UI must survive it.
    DB::table('annual_plans')->where('id', $plan->id)->delete();

    Livewire::actingAs($user)
        ->test('pages::targets.index')
        ->assertSee('Orphaned target indicator')
        ->assertSee('Source no longer available');
});

test('a target with no project reads as organisation wide', function () {
    $user = User::factory()->create();

    PlanIndicator::factory()->create([
        'source_type' => 'annual_plan',
        'source_id' => AnnualPlan::factory()->create()->id,
        'indicator_id' => Indicator::factory()->create()->id,
        'project_id' => null,
    ]);

    Livewire::actingAs($user)->test('pages::targets.index')->assertSee('Organisation-wide');
});

test('an unreported target can be deleted', function () {
    $user = User::factory()->create();
    $target = PlanIndicator::factory()->create([
        'source_type' => 'annual_plan',
        'source_id' => AnnualPlan::factory()->create()->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::targets.index')
        ->call('confirmDelete', $target->id)
        ->call('deleteTarget')
        ->assertHasNoErrors();

    expect(DB::table('plan_indicators')->find($target->id))->toBeNull();
});

test('a reported target is not deleted because its reports would cascade', function () {
    $user = User::factory()->create();
    $target = PlanIndicator::factory()->create([
        'source_type' => 'annual_plan',
        'source_id' => AnnualPlan::factory()->create()->id,
    ]);
    $report = Report::factory()->create(['plan_indicator_id' => $target->id]);

    Livewire::actingAs($user)
        ->test('pages::targets.index')
        ->call('confirmDelete', $target->id)
        ->call('deleteTarget');

    expect(DB::table('plan_indicators')->find($target->id))->not->toBeNull()
        ->and(DB::table('reports')->find($report->id))->not->toBeNull();
});

test('a target value that would overflow the decimal column is rejected', function () {
    $user = User::factory()->create();
    $plan = AnnualPlan::factory()->create();
    $indicator = Indicator::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::targets.index')
        ->call('openCreateModal')
        ->set('targetSourceType', 'annual_plan')
        ->set('targetSourceId', (string) $plan->id)
        ->set('targetIndicatorId', (string) $indicator->id)
        ->set('targetValue', '10000000000')
        ->call('saveTarget')
        ->assertHasErrors(['targetValue' => 'max']);
});

test('the target directory renders with no targets', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::targets.index')->assertSee('No targets yet');
});

test('achievement counts only approved reports and sums numeric indicators', function () {
    $user = User::factory()->create();
    $target = PlanIndicator::factory()->create([
        'source_type' => 'annual_plan',
        'source_id' => AnnualPlan::factory()->create()->id,
        'indicator_id' => Indicator::factory()->create(['measure' => 'numeric'])->id,
        'target' => 200,
    ]);

    Report::factory()->for($target, 'planIndicator')->create(['value' => 50, 'status' => 'approved']);
    Report::factory()->for($target, 'planIndicator')->create(['value' => 30, 'status' => 'approved']);
    Report::factory()->for($target, 'planIndicator')->create(['value' => 100, 'status' => 'reviewed']);

    $component = Livewire::actingAs($user)->test('pages::targets.index');
    $row = $component->instance()->targets->first();

    expect((float) $row->actual_value)->toBe(80.0)
        ->and((float) $row->pending_value)->toBe(100.0);

    $component->assertSee('40')->assertSee('of 200');
});

test('a percentage indicator uses its latest approved value instead of a sum', function () {
    $user = User::factory()->create();
    $target = PlanIndicator::factory()->create([
        'source_type' => 'annual_plan',
        'source_id' => AnnualPlan::factory()->create()->id,
        'indicator_id' => Indicator::factory()->create(['measure' => 'percentage'])->id,
        'target' => 80,
    ]);

    Report::factory()->for($target, 'planIndicator')->create(['value' => 40, 'status' => 'approved', 'period_end' => '2026-03-31']);
    Report::factory()->for($target, 'planIndicator')->create(['value' => 55, 'status' => 'approved', 'period_end' => '2026-06-30']);

    $row = Livewire::actingAs($user)->test('pages::targets.index')->instance()->targets->first();

    expect((float) $row->actual_value)->toBe(55.0);
});

test('the pulse counts and filters targets by achievement status', function () {
    $user = User::factory()->create();
    $plan = AnnualPlan::factory()->create();
    $make = fn (string $name, float $target) => PlanIndicator::factory()->create([
        'source_type' => 'annual_plan',
        'source_id' => $plan->id,
        'indicator_id' => Indicator::factory()->create(['name' => $name, 'measure' => 'numeric'])->id,
        'target' => $target,
    ]);

    Report::factory()->for($make('Reached indicator', 10), 'planIndicator')->create(['value' => 12, 'status' => 'approved']);
    Report::factory()->for($make('Halfway indicator', 10), 'planIndicator')->create(['value' => 5, 'status' => 'approved']);
    $make('Untouched indicator', 10);

    $component = Livewire::actingAs($user)->test('pages::targets.index');

    expect($component->instance()->pulse)->toMatchArray(['total' => 3, 'achieved' => 1, 'in_progress' => 1, 'not_started' => 1]);

    $component->call('filterStatus', 'achieved')
        ->assertSee('Reached indicator')
        ->assertDontSee('Halfway indicator')
        ->assertDontSee('Untouched indicator')
        ->call('filterStatus', 'not_started')
        ->assertSee('Untouched indicator')
        ->assertDontSee('Reached indicator')
        ->call('filterStatus', 'not_started')
        ->assertSet('status', '')
        ->assertSee('Halfway indicator');
});

test('confirming a delete opens the dialog and cancelling closes it', function () {
    $user = User::factory()->create();
    $target = PlanIndicator::factory()->create([
        'source_type' => 'annual_plan',
        'source_id' => AnnualPlan::factory()->create()->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::targets.index')
        ->call('confirmDelete', $target->id)
        ->assertSet('showDeleteModal', true)
        ->call('cancelDelete')
        ->assertSet('showDeleteModal', false)
        ->assertSet('deletingTargetId', null);
});

test('a target shows the program behind its plan and the donors behind its proposal', function () {
    $user = User::factory()->create();
    $program = Program::factory()->create(['name' => 'Livelihoods Programme']);
    $plan = AnnualPlan::factory()->create(['program_id' => $program->id]);
    $proposal = Proposal::factory()->create(['name' => 'Winter Response Proposal']);
    $donor = Donor::factory()->create(['name' => 'Northern Relief Fund']);
    $proposal->donors()->attach($donor, ['budget' => 125000, 'currency' => 'EUR']);

    PlanIndicator::factory()->create(['source_type' => 'annual_plan', 'source_id' => $plan->id]);
    PlanIndicator::factory()->create(['source_type' => 'proposal', 'source_id' => $proposal->id]);

    Livewire::actingAs($user)
        ->test('pages::targets.index')
        ->assertSee('Livelihoods Programme')
        ->assertSee(route('programs.show', $program), false)
        ->assertSee('Winter Response Proposal')
        ->assertSee('Northern Relief Fund')
        ->assertSee('125,000.00')
        ->assertSee(route('donors.show', $donor), false);
});

test('expanding a target lists its reports with links', function () {
    $user = User::factory()->create();
    $target = PlanIndicator::factory()->create([
        'source_type' => 'annual_plan',
        'source_id' => AnnualPlan::factory()->create()->id,
    ]);
    $report = Report::factory()->for($target, 'planIndicator')->create(['status' => 'reviewed']);

    Livewire::actingAs($user)
        ->test('pages::targets.index')
        ->assertDontSee(route('reports.show', $report), false)
        ->assertSee('1 awaiting approval')
        ->call('toggleReports', $target->id)
        ->assertSet('expandedTargetId', $target->id)
        ->assertSee(route('reports.show', $report), false)
        ->call('toggleReports', $target->id)
        ->assertSet('expandedTargetId', null);
});

test('a result with more targets than a page never splits across pages', function () {
    $user = User::factory()->create();
    $plan = AnnualPlan::factory()->create();
    $indicator = Indicator::factory()->create(['name' => 'Crowded indicator']);

    PlanIndicator::factory()->count(20)->create([
        'source_type' => 'annual_plan',
        'source_id' => $plan->id,
        'indicator_id' => $indicator->id,
    ]);

    $component = Livewire::actingAs($user)->test('pages::targets.index');

    expect($component->instance()->targets)->toHaveCount(20)
        ->and($component->instance()->resultPage->total())->toBe(1);
});

test('targets can be filtered by donor, program and year', function () {
    $user = User::factory()->create();

    $program = Program::factory()->create();
    $plan2026 = AnnualPlan::factory()->create(['program_id' => $program->id, 'year' => 2026]);
    $plan2025 = AnnualPlan::factory()->create(['year' => 2025]);
    $proposal = Proposal::factory()->create(['planned_date_begin' => '2025-06-01', 'planned_date_end' => '2027-05-31']);
    $donor = Donor::factory()->create();
    $proposal->donors()->attach($donor, ['budget' => 5000, 'currency' => 'USD']);

    $make = fn (string $name, string $type, int $sourceId) => PlanIndicator::factory()->create([
        'source_type' => $type,
        'source_id' => $sourceId,
        'indicator_id' => Indicator::factory()->create(['name' => $name])->id,
        'project_id' => null,
    ]);

    $make('Programme plan indicator', 'annual_plan', $plan2026->id);
    $make('Older plan indicator', 'annual_plan', $plan2025->id);
    $make('Donor funded indicator', 'proposal', $proposal->id);

    Livewire::actingAs($user)
        ->test('pages::targets.index')
        ->set('donor', (string) $donor->id)
        ->assertSee('Donor funded indicator')
        ->assertDontSee('Programme plan indicator')
        ->call('clearFilters')
        ->set('program', (string) $program->id)
        ->assertSee('Programme plan indicator')
        ->assertDontSee('Older plan indicator')
        ->assertDontSee('Donor funded indicator')
        ->call('clearFilters')
        ->set('year', '2026')
        ->assertSee('Programme plan indicator')
        ->assertSee('Donor funded indicator')
        ->assertDontSee('Older plan indicator');
});

test('the report trail shows evidence files and MEAL visibility', function () {
    $user = User::factory()->create();
    $target = PlanIndicator::factory()->create([
        'source_type' => 'annual_plan',
        'source_id' => AnnualPlan::factory()->create()->id,
    ]);
    Report::factory()->for($target, 'planIndicator')->create(['is_seen_by_meal' => true]);

    Livewire::actingAs($user)
        ->test('pages::targets.index')
        ->call('toggleReports', $target->id)
        ->assertSee('No evidence files')
        ->assertSee('Seen by MEAL');
});

test('the indicator chain can open the commit form already tied to an indicator', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $indicator = Indicator::factory()->create(['project_id' => $project->id, 'frequency' => Period::Monthly]);

    Livewire::actingAs($user)
        ->withQueryParams(['indicator' => $indicator->id, 'create' => 1])
        ->test('pages::targets.index')
        ->assertSet('showTargetModal', true)
        ->assertSet('targetIndicatorId', (string) $indicator->id)
        ->assertSet('targetPeriod', Period::Monthly->value)
        ->assertSet('targetProjectId', (string) $project->id);
});

test('the form offers only the plans and projects that fit the chosen indicator and source', function () {
    $user = User::factory()->create();
    $program = Program::factory()->create();
    $indicator = Indicator::factory()->for(Result::factory()->for($program))->create();
    $fittingPlan = AnnualPlan::factory()->for($program)->create();
    AnnualPlan::factory()->create();
    $programProject = Project::factory()->for($program)->create();
    Project::factory()->create();

    $proposal = Proposal::factory()->create();
    $funded = Project::factory()->create();
    $proposal->projects()->attach($funded);

    $component = Livewire::actingAs($user)
        ->test('pages::targets.index')
        ->call('openCreateModal')
        ->set('targetIndicatorId', (string) $indicator->id);

    expect(collect($component->instance()->sourceOptions())->pluck('id')->all())->toBe([$fittingPlan->id]);

    $component->set('targetSourceId', (string) $fittingPlan->id);
    expect($component->instance()->formProjectOptions()->modelKeys())->toBe([$programProject->id]);

    $component->set('targetSourceType', 'proposal')->set('targetSourceId', (string) $proposal->id);
    expect($component->instance()->formProjectOptions()->modelKeys())->toBe([$funded->id]);
});

test('a target that does not fit its chain is refused', function () {
    $user = User::factory()->create();
    $indicator = Indicator::factory()->for(Result::factory()->for(Program::factory()))->create();
    $otherPlan = AnnualPlan::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::targets.index')
        ->call('openCreateModal')
        ->set('targetIndicatorId', (string) $indicator->id)
        ->set('targetSourceType', 'annual_plan')
        ->set('targetSourceId', (string) $otherPlan->id)
        ->set('targetValue', '100')
        ->call('saveTarget')
        ->assertHasErrors(['targetSourceId']);

    $proposal = Proposal::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::targets.index')
        ->call('openCreateModal')
        ->set('targetIndicatorId', (string) Indicator::factory()->create()->id)
        ->set('targetSourceType', 'proposal')
        ->set('targetSourceId', (string) $proposal->id)
        ->set('targetProjectId', (string) Project::factory()->create()->id)
        ->set('targetValue', '100')
        ->call('saveTarget')
        ->assertHasErrors(['targetProjectId']);

    expect(PlanIndicator::query()->count())->toBe(0);
});

test('a percentage target above 100 is refused', function () {
    $user = User::factory()->create();
    $indicator = Indicator::factory()->create(['measure' => IndicatorMeasure::Percentage]);

    Livewire::actingAs($user)
        ->test('pages::targets.index')
        ->call('openCreateModal')
        ->set('targetIndicatorId', (string) $indicator->id)
        ->set('targetSourceId', (string) AnnualPlan::factory()->create()->id)
        ->set('targetValue', '120')
        ->call('saveTarget')
        ->assertHasErrors(['targetValue']);

    expect(PlanIndicator::query()->exists())->toBeFalse();
});

test('the same indicator cannot be targeted twice in one source for one project', function () {
    $user = User::factory()->create();
    $existing = PlanIndicator::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::targets.index')
        ->call('openCreateModal')
        ->set('targetIndicatorId', (string) $existing->indicator_id)
        ->set('targetSourceType', 'annual_plan')
        ->set('targetSourceId', (string) $existing->source_id)
        ->set('targetValue', '500')
        ->call('saveTarget')
        ->assertHasErrors(['targetIndicatorId']);

    expect(PlanIndicator::query()->count())->toBe(1);
});

test('a reported target keeps its indicator and source but its value can still change', function () {
    $user = User::factory()->create();
    $target = PlanIndicator::factory()->create(['target' => 100]);
    Report::factory()->for($target)->create();

    Livewire::actingAs($user)
        ->test('pages::targets.index')
        ->call('openEditModal', $target->id)
        ->set('targetIndicatorId', (string) Indicator::factory()->create()->id)
        ->call('saveTarget')
        ->assertHasErrors(['targetIndicatorId']);

    Livewire::actingAs($user)
        ->test('pages::targets.index')
        ->call('openEditModal', $target->id)
        ->set('targetValue', '250')
        ->call('saveTarget')
        ->assertHasNoErrors();

    expect((float) DB::table('plan_indicators')->find($target->id)->target)->toBe(250.0);
});

test('a target already out of step is flagged but its value can still be corrected', function () {
    $user = User::factory()->create();
    $indicator = Indicator::factory()->for(Result::factory()->for(Program::factory()))->create();
    $target = PlanIndicator::factory()
        ->forAnnualPlan(AnnualPlan::factory()->create())
        ->create(['indicator_id' => $indicator->id, 'target' => 100]);

    Livewire::actingAs($user)
        ->test('pages::targets.index')
        ->assertSeeHtml('data-test="chain-issue-'.$target->id.'"')
        ->assertSee(__('targets.issue_plan_program_differs_from_result'))
        ->call('openEditModal', $target->id)
        ->set('targetValue', '150')
        ->call('saveTarget')
        ->assertHasNoErrors();

    expect((float) DB::table('plan_indicators')->find($target->id)->target)->toBe(150.0);
});

test('targets can be scoped to one indicator or one result from a deep link', function () {
    $user = User::factory()->create();
    $shown = Indicator::factory()->create(['name' => 'Scoped indicator']);
    $hidden = Indicator::factory()->create(['name' => 'Other indicator']);
    PlanIndicator::factory()->create(['indicator_id' => $shown->id]);
    PlanIndicator::factory()->create(['indicator_id' => $hidden->id]);

    Livewire::actingAs($user)
        ->withQueryParams(['indicator' => $shown->id])
        ->test('pages::targets.index')
        ->assertSee('Scoped indicator')
        ->assertDontSee('Other indicator')
        ->assertSeeHtml('data-test="indicator-scope-chip"')
        ->call('clearIndicatorScope')
        ->assertSee('Other indicator');

    Livewire::actingAs($user)
        ->withQueryParams(['result' => $shown->result_id])
        ->test('pages::targets.index')
        ->assertSee('Scoped indicator')
        ->assertDontSee('Other indicator');
});

test('the program filter reaches an organisation-wide proposal target through its result', function () {
    $user = User::factory()->create();
    $program = Program::factory()->create();
    $indicator = Indicator::factory()->for(Result::factory()->for($program))->create(['name' => 'Program logframe indicator']);
    PlanIndicator::factory()->forProposal(Proposal::factory()->create())->create(['indicator_id' => $indicator->id]);

    Livewire::actingAs($user)
        ->test('pages::targets.index')
        ->set('program', (string) $program->id)
        ->assertSee('Program logframe indicator');
});

test('each target links to recording a report against it', function () {
    $user = User::factory()->create();
    $target = PlanIndicator::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::targets.index')
        ->assertSeeHtml('href="'.e(route('reports.index', ['create' => 1, 'target' => $target->id])).'"');
});
