<?php

use App\Enums\Period;
use App\Enums\ResultType;
use App\Models\AnnualPlan;
use App\Models\DonorProposal;
use App\Models\Indicator;
use App\Models\IndicatorProgress;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Report;
use App\Models\Result;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('guests are redirected from the results framework', function () {
    $this->get(route('results.index'))->assertRedirect(route('login'));
});

test('results render as a nested logframe grouped by program', function () {
    $user = User::factory()->create();
    $program = Program::factory()->create(['name' => 'Water Security']);

    $impact = Result::factory()->create([
        'program_id' => $program->id,
        'statement' => 'Reduced waterborne disease.',
        'type' => ResultType::Impact,
    ]);
    Result::factory()->create([
        'program_id' => $program->id,
        'parent_id' => $impact->id,
        'statement' => 'Improved access to safe water.',
        'type' => ResultType::Outcome,
    ]);

    Livewire::actingAs($user)
        ->test('pages::results.index')
        ->assertSee('Water Security')
        ->assertSee('Reduced waterborne disease.')
        ->assertSee('Improved access to safe water.')
        ->assertSee('data-tree-depth="1"', false);
});

test('a result with no program still appears under unassigned', function () {
    $user = User::factory()->create();

    Result::factory()->create([
        'program_id' => null,
        'statement' => 'Orphaned statement.',
    ]);

    Livewire::actingAs($user)
        ->test('pages::results.index')
        ->assertSee('Unassigned')
        ->assertSee('Orphaned statement.');
});

test('results can be filtered by program and type', function () {
    $user = User::factory()->create();
    $first = Program::factory()->create();
    $second = Program::factory()->create();

    Result::factory()->create(['program_id' => $first->id, 'statement' => 'First impact.', 'type' => ResultType::Impact]);
    Result::factory()->create(['program_id' => $second->id, 'statement' => 'Second output.', 'type' => ResultType::Output]);

    Livewire::actingAs($user)
        ->test('pages::results.index')
        ->set('program', (string) $first->id)
        ->assertSee('First impact.')
        ->assertDontSee('Second output.')
        ->call('clearFilters')
        ->set('type', ResultType::Output->value)
        ->assertDontSee('First impact.')
        ->assertSee('Second output.');
});

test('a result can be created under a parent', function () {
    $user = User::factory()->create();
    $program = Program::factory()->create();
    $parent = Result::factory()->create(['program_id' => $program->id, 'type' => ResultType::Outcome]);

    Livewire::actingAs($user)
        ->test('pages::results.index')
        ->call('openCreateModal')
        ->set('statement', '  Latrines constructed and handed over.  ')
        ->set('resultType', ResultType::Output->value)
        ->set('checkoutPeriod', Period::Quarterly->value)
        ->set('resultProgramId', (string) $program->id)
        ->set('parentId', (string) $parent->id)
        ->call('saveResult')
        ->assertHasNoErrors();

    $result = Result::query()->where('statement', 'Latrines constructed and handed over.')->sole();

    expect($result->parent_id)->toBe($parent->id)
        ->and($result->type)->toBe(ResultType::Output);
});

test('the parent picker excludes the edited result and its own descendants', function () {
    $user = User::factory()->create();

    $root = Result::factory()->create(['statement' => 'Root statement.', 'type' => ResultType::Outcome]);
    Result::factory()->create(['parent_id' => $root->id, 'statement' => 'Child statement.', 'type' => ResultType::Output]);
    Result::factory()->create(['statement' => 'Unrelated statement.', 'type' => ResultType::Impact]);

    $component = Livewire::actingAs($user)
        ->test('pages::results.index')
        ->call('openEditModal', $root->id);

    $statements = $component->instance()->parentOptions()->pluck('statement')->all();

    expect($statements)->toContain('Unrelated statement.')
        ->not->toContain('Root statement.')
        ->not->toContain('Child statement.');
});

test('a leaf result with no indicators can be deleted', function () {
    $user = User::factory()->create();
    $result = Result::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::results.index')
        ->call('confirmDelete', $result->id)
        ->call('deleteResult')
        ->assertHasNoErrors();

    expect(DB::table('results')->find($result->id))->toBeNull();
});

test('a result with children is not deleted because the subtree would cascade', function () {
    $user = User::factory()->create();
    $parent = Result::factory()->create();
    $child = Result::factory()->create(['parent_id' => $parent->id]);

    Livewire::actingAs($user)
        ->test('pages::results.index')
        ->call('confirmDelete', $parent->id)
        ->call('deleteResult');

    expect(DB::table('results')->find($parent->id))->not->toBeNull()
        ->and(DB::table('results')->find($child->id))->not->toBeNull();
});

test('a result carrying indicators is not deleted because targets and reports would cascade', function () {
    $user = User::factory()->create();
    $result = Result::factory()->create();
    $indicator = Indicator::factory()->create(['result_id' => $result->id]);

    Livewire::actingAs($user)
        ->test('pages::results.index')
        ->call('confirmDelete', $result->id)
        ->call('deleteResult');

    expect(DB::table('results')->find($result->id))->not->toBeNull()
        ->and(DB::table('indicators')->find($indicator->id))->not->toBeNull();
});

test('the results framework renders with no results', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::results.index')->assertSee('No results yet');
});

test('the overview counts statements, indicators, targets and measurement coverage', function () {
    $user = User::factory()->create();
    $program = Program::factory()->create();

    $measured = Result::factory()->create(['program_id' => $program->id, 'type' => ResultType::Outcome]);
    Result::factory()->create(['program_id' => $program->id, 'type' => ResultType::Output]);

    $indicator = Indicator::factory()->create([
        'result_id' => $measured->id,
        'baseline' => 0,
        'target' => 100,
    ]);
    IndicatorProgress::factory()->create(['indicator_id' => $indicator->id, 'value' => 100]);
    PlanIndicator::factory()->create(['indicator_id' => $indicator->id]);

    $overview = Livewire::actingAs($user)
        ->test('pages::results.index')
        ->instance()
        ->overview();

    expect($overview['statements'])->toBe(2)
        ->and($overview['measured'])->toBe(1)
        ->and($overview['indicators'])->toBe(1)
        ->and($overview['targets'])->toBe(1)
        ->and($overview['coverage'])->toBe(50.0)
        ->and($overview['levels'][ResultType::Outcome->value]['measured'])->toBe(1)
        ->and($overview['levels'][ResultType::Output->value]['measured'])->toBe(0);
});

test('the search matches an indicator name as well as a statement', function () {
    $user = User::factory()->create();

    $measured = Result::factory()->create(['statement' => 'Households reached with cash.']);
    Result::factory()->create(['statement' => 'Unrelated statement.']);
    Indicator::factory()->create(['result_id' => $measured->id, 'name' => 'Litres of water distributed']);

    Livewire::actingAs($user)
        ->test('pages::results.index')
        ->set('search', 'litres of water')
        ->assertSee('Households reached with cash.')
        ->assertDontSee('Unrelated statement.');
});

test('the needs indicators chip keeps only statements carrying no indicator', function () {
    $user = User::factory()->create();

    $measured = Result::factory()->create(['statement' => 'Measured statement.']);
    Result::factory()->create(['statement' => 'Unmeasured statement.']);
    Indicator::factory()->create(['result_id' => $measured->id]);

    Livewire::actingAs($user)
        ->test('pages::results.index')
        ->call('setFocus', 'unmeasured')
        ->assertSet('focus', 'unmeasured')
        ->assertSee('Unmeasured statement.')
        ->assertDontSee('Measured statement.')
        ->call('setFocus', 'unmeasured')
        ->assertSet('focus', '')
        ->assertSee('Measured statement.');
});

test('the off track chip keeps only statements whose indicators are rating amber or red', function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-30 12:00:00'));

    $user = User::factory()->create();

    $behind = Result::factory()->create(['statement' => 'Statement falling behind.']);
    $ahead = Result::factory()->create(['statement' => 'Statement on track.']);

    $behindIndicator = Indicator::factory()->create(['result_id' => $behind->id, 'baseline' => 0, 'target' => 100]);
    IndicatorProgress::factory()->create(['indicator_id' => $behindIndicator->id, 'value' => 2]);

    $aheadIndicator = Indicator::factory()->create(['result_id' => $ahead->id, 'baseline' => 0, 'target' => 100]);
    IndicatorProgress::factory()->create(['indicator_id' => $aheadIndicator->id, 'value' => 100]);

    Livewire::actingAs($user)
        ->test('pages::results.index')
        ->call('setFocus', 'offtrack')
        ->assertSee('Statement falling behind.')
        ->assertDontSee('Statement on track.');
});

test('a statement whose parent a filter hides is promoted to the top and keeps its lineage', function () {
    $user = User::factory()->create();
    $first = Program::factory()->create();
    $second = Program::factory()->create();

    $impact = Result::factory()->create([
        'program_id' => $first->id,
        'statement' => 'Parent impact statement.',
        'type' => ResultType::Impact,
    ]);
    Result::factory()->create([
        'program_id' => $second->id,
        'parent_id' => $impact->id,
        'statement' => 'Child outcome statement.',
        'type' => ResultType::Outcome,
    ]);

    Livewire::actingAs($user)
        ->test('pages::results.index')
        ->set('program', (string) $second->id)
        ->assertSee('Child outcome statement.')
        // The hidden parent is named as lineage, not drawn as a card of its own.
        ->assertSee('Parent impact statement.')
        ->assertDontSee('data-result-type="impact"', false);
});

test('the matrix view lists every visible statement', function () {
    $user = User::factory()->create();
    $program = Program::factory()->create();

    $impact = Result::factory()->create([
        'program_id' => $program->id,
        'statement' => 'Matrix impact statement.',
        'type' => ResultType::Impact,
    ]);
    Result::factory()->create([
        'program_id' => $program->id,
        'parent_id' => $impact->id,
        'statement' => 'Matrix output statement.',
        'type' => ResultType::Output,
    ]);

    Livewire::actingAs($user)
        ->test('pages::results.index')
        ->set('view', 'matrix')
        ->assertSee('Matrix impact statement.')
        ->assertSee('Matrix output statement.')
        ->assertSee('data-tree-depth="1"', false);
});

test('adding a result from a statement pre-wires it as the parent', function () {
    $user = User::factory()->create();
    $program = Program::factory()->create();
    $parent = Result::factory()->create(['program_id' => $program->id, 'type' => ResultType::Impact]);

    Livewire::actingAs($user)
        ->test('pages::results.index')
        ->call('openCreateModal', $parent->id)
        ->assertSet('showResultModal', true)
        ->assertSet('parentId', (string) $parent->id)
        ->assertSet('resultProgramId', (string) $program->id)
        ->assertSet('resultType', ResultType::Outcome->value);
});

test('confirming a delete opens the dialog, and cancelling closes it', function () {
    $user = User::factory()->create();
    $result = Result::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::results.index')
        ->call('confirmDelete', $result->id)
        ->assertSet('deletingResultId', $result->id)
        // The dialog is driven by the boolean; asserting the id alone passes while the UI stays dead.
        ->assertSet('showDeleteModal', true)
        ->call('cancelDelete')
        ->assertSet('deletingResultId', null)
        ->assertSet('showDeleteModal', false);
});

test('a statement shows the plan, the proposal and the donor its targets were committed in', function () {
    $user = User::factory()->create();
    $program = Program::factory()->create();
    $result = Result::factory()->create(['program_id' => $program->id, 'statement' => 'Funded output statement.']);
    $indicator = Indicator::factory()->create(['result_id' => $result->id, 'name' => 'Households reached']);

    $plan = AnnualPlan::factory()->create(['program_id' => $program->id, 'year' => 2026]);
    $planTarget = PlanIndicator::factory()->forAnnualPlan($plan)->create(['indicator_id' => $indicator->id]);
    Report::factory()->approved()->create(['plan_indicator_id' => $planTarget->id]);

    $agreement = DonorProposal::factory()->create();
    PlanIndicator::factory()->forProposal($agreement->proposal)->create(['indicator_id' => $indicator->id]);

    Livewire::actingAs($user)
        ->test('pages::results.index')
        ->assertSee('Funded output statement.')
        // The plan the target sits in, the proposal, and the donor behind it.
        ->assertSee('Annual plan 2026')
        ->assertSee($agreement->proposal->name)
        ->assertSee($agreement->donor->name)
        ->assertSee('1 reported · 1 approved');
});

test('the attention chips count under the other active filters, not the whole table', function () {
    $user = User::factory()->create();
    $first = Program::factory()->create();
    $second = Program::factory()->create();

    Result::factory()->create(['program_id' => $first->id, 'statement' => 'Unmeasured in first.']);
    Result::factory()->create(['program_id' => $second->id, 'statement' => 'Unmeasured in second.']);

    $component = Livewire::actingAs($user)->test('pages::results.index');

    expect($component->instance()->focusCounts())->toBe(['unmeasured' => 2, 'offtrack' => 0]);

    $scoped = $component->set('program', (string) $first->id);

    expect($scoped->instance()->focusCounts())->toBe(['unmeasured' => 1, 'offtrack' => 0]);
});

test('a level in the results chain toggles the type filter', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::results.index')
        ->call('setType', ResultType::Output->value)
        ->assertSet('type', ResultType::Output->value)
        ->call('setType', ResultType::Output->value)
        ->assertSet('type', '');
});

test('the parent picker offers only statements one level up the chain', function () {
    $user = User::factory()->create();
    Result::factory()->type(ResultType::Impact)->create(['statement' => 'An impact.']);
    Result::factory()->type(ResultType::Outcome)->create(['statement' => 'An outcome.']);
    Result::factory()->type(ResultType::Output)->create(['statement' => 'An output.']);

    $component = Livewire::actingAs($user)
        ->test('pages::results.index')
        ->call('openCreateModal')
        ->set('resultType', ResultType::Output->value);

    expect($component->instance()->parentOptions()->pluck('statement')->all())->toBe(['An outcome.']);

    $component->set('resultType', ResultType::Outcome->value);
    expect($component->instance()->parentOptions()->pluck('statement')->all())->toBe(['An impact.']);

    $component->set('resultType', ResultType::Impact->value);
    expect($component->instance()->parentOptions())->toBeEmpty();
});

test('a result cannot contribute to a statement that is not directly above it', function () {
    $user = User::factory()->create();
    $impact = Result::factory()->type(ResultType::Impact)->create();

    Livewire::actingAs($user)
        ->test('pages::results.index')
        ->call('openCreateModal')
        ->set('statement', 'Wells repaired.')
        ->set('resultType', ResultType::Output->value)
        ->set('parentId', (string) $impact->id)
        ->call('saveResult')
        ->assertHasErrors(['parentId']);

    expect(Result::query()->where('statement', 'Wells repaired.')->exists())->toBeFalse();
});

test('changing the level clears a parent that can no longer hold it', function () {
    $user = User::factory()->create();
    $outcome = Result::factory()->type(ResultType::Outcome)->create();

    Livewire::actingAs($user)
        ->test('pages::results.index')
        ->call('openCreateModal', $outcome->id)
        ->assertSet('parentId', (string) $outcome->id)
        ->set('resultType', ResultType::Outcome->value)
        ->assertSet('parentId', '');
});

test('nothing can be added under an output', function () {
    $user = User::factory()->create();
    $output = Result::factory()->type(ResultType::Output)->create();

    Livewire::actingAs($user)
        ->test('pages::results.index')
        ->assertDontSeeHtml('data-test="add-child-result-'.$output->id.'"')
        ->call('openCreateModal', $output->id)
        ->assertSet('parentId', '');
});

test('a contributing result always takes its parent program', function () {
    $user = User::factory()->create();
    $program = Program::factory()->create();
    $outcome = Result::factory()->type(ResultType::Outcome)->create(['program_id' => $program->id]);

    Livewire::actingAs($user)
        ->test('pages::results.index')
        ->call('openCreateModal')
        ->set('resultType', ResultType::Output->value)
        ->set('statement', 'Latrines built.')
        ->set('parentId', (string) $outcome->id)
        ->assertSet('resultProgramId', (string) $program->id)
        ->set('resultProgramId', (string) Program::factory()->create()->id)
        ->call('saveResult')
        ->assertHasNoErrors();

    expect(Result::query()->where('statement', 'Latrines built.')->sole()->program_id)->toBe($program->id);
});

test('a level change is refused while nested results sit at the old level below it', function () {
    $user = User::factory()->create();
    $outcome = Result::factory()->type(ResultType::Outcome)->create();
    Result::factory()->type(ResultType::Output)->create(['parent_id' => $outcome->id]);

    Livewire::actingAs($user)
        ->test('pages::results.index')
        ->call('openEditModal', $outcome->id)
        ->set('resultType', ResultType::Impact->value)
        ->call('saveResult')
        ->assertHasErrors(['resultType']);

    expect($outcome->fresh()->type)->toBe(ResultType::Outcome);
});

test('moving a statement to another program moves its whole subtree', function () {
    $user = User::factory()->create();
    $from = Program::factory()->create();
    $to = Program::factory()->create();
    $impact = Result::factory()->type(ResultType::Impact)->create(['program_id' => $from->id]);
    $outcome = Result::factory()->type(ResultType::Outcome)->create(['program_id' => $from->id, 'parent_id' => $impact->id]);
    $output = Result::factory()->type(ResultType::Output)->create(['program_id' => $from->id, 'parent_id' => $outcome->id]);

    Livewire::actingAs($user)
        ->test('pages::results.index')
        ->call('openEditModal', $impact->id)
        ->set('resultProgramId', (string) $to->id)
        ->call('saveResult')
        ->assertHasNoErrors();

    expect(DB::table('results')->whereIn('id', [$impact->id, $outcome->id, $output->id])->pluck('program_id')->unique()->values()->all())
        ->toBe([$to->id]);
});

test('a program move is refused when it would strand an indicator or a plan target', function () {
    $user = User::factory()->create();
    $from = Program::factory()->create();
    $to = Program::factory()->create();

    $scoped = Result::factory()->type(ResultType::Outcome)->create(['program_id' => $from->id]);
    Indicator::factory()->create(['result_id' => $scoped->id, 'project_id' => Project::factory()->create(['program_id' => $from->id])->id]);

    $planned = Result::factory()->type(ResultType::Outcome)->create(['program_id' => $from->id]);
    PlanIndicator::factory()
        ->forAnnualPlan(AnnualPlan::factory()->create(['program_id' => $from->id]))
        ->create(['indicator_id' => Indicator::factory()->create(['result_id' => $planned->id])->id]);

    foreach ([$scoped, $planned] as $result) {
        Livewire::actingAs($user)
            ->test('pages::results.index')
            ->call('openEditModal', $result->id)
            ->set('resultProgramId', (string) $to->id)
            ->call('saveResult')
            ->assertHasErrors(['resultProgramId']);

        expect($result->fresh()->program_id)->toBe($from->id);
    }
});

test('a target reads its achievement from approved reports only, and an unmeasured statement links to adding an indicator', function () {
    $user = User::factory()->create();
    $measured = Result::factory()->type(ResultType::Outcome)->create();
    $unmeasured = Result::factory()->type(ResultType::Outcome)->create();
    $target = PlanIndicator::factory()->create([
        'indicator_id' => Indicator::factory()->create(['result_id' => $measured->id])->id,
        'target' => 200,
    ]);
    Report::factory()->approved()->for($target)->create(['value' => 50]);
    Report::factory()->for($target)->create(['value' => 150]);

    Livewire::actingAs($user)
        ->test('pages::results.index')
        ->assertSee('25% achieved')
        ->assertDontSee('100% achieved')
        ->assertSeeHtml('href="'.route('indicators.index', ['create' => $unmeasured->id]).'"');
});
