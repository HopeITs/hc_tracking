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
use App\Models\Proposal;
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
    $user = User::factory()->role('General Manager')->create();
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
    $user = User::factory()->role('General Manager')->create();

    Result::factory()->create([
        'program_id' => null,
        'statement' => 'Orphaned statement.',
    ]);

    Livewire::actingAs($user)
        ->test('pages::results.index')
        ->assertSee('Unassigned')
        ->assertSee('Orphaned statement.');
});

test('results can be filtered by program', function () {
    $user = User::factory()->role('General Manager')->create();
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
        ->assertSee('First impact.')
        ->assertSee('Second output.');
});

test('a program with a purely numeric name does not break either view', function () {
    $user = User::factory()->role('General Manager')->create();
    $program = Program::factory()->create(['name' => '2024']);

    Result::factory()->create([
        'program_id' => $program->id,
        'statement' => 'Numeric program statement.',
    ]);

    Livewire::actingAs($user)
        ->test('pages::results.index')
        ->assertOk()
        ->assertSee('Numeric program statement.')
        ->assertOk()
        ->assertSee('Numeric program statement.');
});

test('a result can be created under a parent', function () {
    $user = User::factory()->role('General Manager')->create();
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
    $user = User::factory()->role('General Manager')->create();

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
    $user = User::factory()->role('General Manager')->create();
    $result = Result::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::results.index')
        ->call('confirmDelete', $result->id)
        ->call('deleteResult')
        ->assertHasNoErrors();

    expect(DB::table('results')->find($result->id))->toBeNull();
});

test('a result with children is not deleted because the subtree would cascade', function () {
    $user = User::factory()->role('General Manager')->create();
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
    $user = User::factory()->role('General Manager')->create();
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
    $user = User::factory()->role('General Manager')->create();

    Livewire::actingAs($user)->test('pages::results.index')->assertSee('No results yet');
});

test('the search matches an indicator name as well as a statement', function () {
    $user = User::factory()->role('General Manager')->create();

    $measured = Result::factory()->create(['statement' => 'Households reached with cash.']);
    Result::factory()->create(['statement' => 'Unrelated statement.']);
    Indicator::factory()->create(['result_id' => $measured->id, 'name' => 'Litres of water distributed']);

    Livewire::actingAs($user)
        ->test('pages::results.index')
        ->set('search', 'litres of water')
        ->assertSee('Households reached with cash.')
        ->assertDontSee('Unrelated statement.');
});

test('a statement whose parent a filter hides is promoted to the top and keeps its lineage', function () {
    $user = User::factory()->role('General Manager')->create();
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

test('the framework lists every visible statement, nested under its program', function () {
    $user = User::factory()->role('General Manager')->create();
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
        ->assertSee('Matrix impact statement.')
        ->assertSee('Matrix output statement.')
        ->assertSee('data-tree-depth="1"', false);
});

test('adding a result from a statement pre-wires it as the parent', function () {
    $user = User::factory()->role('General Manager')->create();
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
    $user = User::factory()->role('General Manager')->create();
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
    $user = User::factory()->role('General Manager')->create();
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

test('the parent picker offers only statements one level up the chain', function () {
    $user = User::factory()->role('General Manager')->create();
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
    $user = User::factory()->role('General Manager')->create();
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
    $user = User::factory()->role('General Manager')->create();
    $outcome = Result::factory()->type(ResultType::Outcome)->create();

    Livewire::actingAs($user)
        ->test('pages::results.index')
        ->call('openCreateModal', $outcome->id)
        ->assertSet('parentId', (string) $outcome->id)
        ->set('resultType', ResultType::Outcome->value)
        ->assertSet('parentId', '');
});

test('nothing can be added under an activity, while an output takes activities', function () {
    $user = User::factory()->role('General Manager')->create();
    $output = Result::factory()->type(ResultType::Output)->create();
    $activity = Result::factory()->activity($output)->create();

    Livewire::actingAs($user)
        ->test('pages::results.index')
        ->assertSeeHtml('data-test="add-child-result-'.$output->id.'"')
        ->assertDontSeeHtml('data-test="add-child-result-'.$activity->id.'"')
        ->call('openCreateModal', $activity->id)
        ->assertSet('parentId', '')
        ->call('openCreateModal', $output->id)
        ->assertSet('parentId', (string) $output->id)
        ->assertSet('resultType', ResultType::Activity->value);
});

test('a contributing result always takes its parent program', function () {
    $user = User::factory()->role('General Manager')->create();
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
    $user = User::factory()->role('General Manager')->create();
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
    $user = User::factory()->role('General Manager')->create();
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
    $user = User::factory()->role('General Manager')->create();
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

test('a target reads its achievement from approved reports only, and an unmeasured statement offers to add an indicator', function () {
    $user = User::factory()->role('General Manager')->create();
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
        ->assertSeeHtml('data-test="add-indicator-for-result-'.$unmeasured->id.'"');
});

test('proposal frameworks stay off the program logframes and show as cards after them', function () {
    $user = User::factory()->role('General Manager')->create();
    $program = Program::factory()->create(['name' => 'Water Security']);
    $proposal = Proposal::factory()->create(['name' => 'Dry Season Response']);
    Proposal::factory()->create(['name' => 'No Logframe Yet']);
    $programImpact = Result::factory()->type(ResultType::Impact)->create(['program_id' => $program->id, 'statement' => 'Program-level impact.']);
    Result::factory()->type(ResultType::Output)->create(['program_id' => $program->id, 'proposal_id' => $proposal->id, 'copied_from_id' => $programImpact->id, 'statement' => 'Proposal-level output.']);

    $component = Livewire::actingAs($user)
        ->test('pages::results.index')
        ->assertSeeInOrder(['Water Security', 'Program-level impact.', __('results_framework.proposal_logframes'), 'Dry Season Response'])
        ->assertDontSee('Proposal-level output.')
        ->assertDontSee('No Logframe Yet')
        ->assertSeeHtml('data-test="proposal-logframe-card-'.$proposal->id.'"')
        ->assertSeeHtml('href="'.e(route('proposals.show', ['proposal' => $proposal, 'ptab' => 'logframe'])).'"');

    expect($component->instance()->groupedRoots->keys()->all())->toBe(['Water Security']);
});

test('the program filter offers only programs with a logframe and narrows the proposal cards too', function () {
    $user = User::factory()->role('General Manager')->create();
    $water = Program::factory()->create(['name' => 'Water Security']);
    $health = Program::factory()->create(['name' => 'Health Access']);
    Program::factory()->create(['name' => 'Empty Program']);
    Result::factory()->type(ResultType::Impact)->create(['program_id' => $water->id, 'statement' => 'Water impact.']);
    Result::factory()->type(ResultType::Impact)->create(['program_id' => $health->id, 'statement' => 'Health impact.']);
    $waterProposal = Proposal::factory()->create(['name' => 'Water Proposal']);
    $healthProposal = Proposal::factory()->create(['name' => 'Health Proposal']);
    Result::factory()->type(ResultType::Impact)->create(['program_id' => $water->id, 'proposal_id' => $waterProposal->id]);
    Result::factory()->type(ResultType::Impact)->create(['program_id' => $health->id, 'proposal_id' => $healthProposal->id]);

    $component = Livewire::actingAs($user)->test('pages::results.index');

    expect($component->instance()->frameworkProgramOptions->pluck('name')->all())->toBe(['Health Access', 'Water Security'])
        ->and($component->instance()->hasUnassignedFramework)->toBeFalse();

    $component->set('program', (string) $water->id)
        ->assertSee('Water impact.')
        ->assertDontSee('Health impact.')
        ->assertSee('Water Proposal')
        ->assertDontSee('Health Proposal');
});

test('the search opens the matching path and narrows the proposal cards by title', function () {
    $user = User::factory()->role('General Manager')->create();
    $program = Program::factory()->create();
    $impact = Result::factory()->type(ResultType::Impact)->create(['program_id' => $program->id, 'statement' => 'Healthier families.']);
    Result::factory()->type(ResultType::Outcome)->create(['program_id' => $program->id, 'parent_id' => $impact->id, 'statement' => 'Clean water reaches homes.']);
    Result::factory()->type(ResultType::Impact)->create(['program_id' => $program->id, 'statement' => 'Unrelated impact.']);
    $winter = Proposal::factory()->create(['name' => 'Winter Clean Water']);
    $summer = Proposal::factory()->create(['name' => 'Summer Schools']);
    Result::factory()->create(['proposal_id' => $winter->id]);
    Result::factory()->create(['proposal_id' => $summer->id]);

    Livewire::actingAs($user)->test('pages::results.index')
        ->set('search', 'clean water')
        ->assertSeeInOrder(['Healthier families.', 'Clean water reaches homes.'])
        ->assertDontSee('Unrelated impact.')
        ->assertSeeHtml('x-data="{ open: true }"')
        ->assertSee('Winter Clean Water')
        ->assertDontSee('Summer Schools');
});

test('a proposal node cannot be reached from the results page', function () {
    $proposalOutput = Result::factory()->type(ResultType::Output)->create(['proposal_id' => Proposal::factory()->create()->id]);

    Livewire::actingAs(mealManager())
        ->test('pages::results.index')
        ->call('openEditModal', $proposalOutput->id)
        ->assertNotFound();
});

test('the results page holds a flat query count across both kinds of framework', function () {
    $user = User::factory()->role('General Manager')->create();
    $program = Program::factory()->create();

    $seed = function (int $branches) use ($program): void {
        $proposal = Proposal::factory()->create();

        foreach (range(1, $branches) as $branch) {
            $programOutcome = Result::factory()->type(ResultType::Outcome)->create(['program_id' => $program->id]);
            insertIndicator(Result::factory()->type(ResultType::Output)->create(['program_id' => $program->id, 'parent_id' => $programOutcome->id])->id);

            $proposalOutput = Result::factory()->type(ResultType::Output)->create(['program_id' => $program->id, 'proposal_id' => $proposal->id, 'contributes_to_result_id' => $programOutcome->id]);
            Result::factory()->activity($proposalOutput)->create();
            insertIndicator($proposalOutput->id);
        }
    };

    $countQueries = function () use ($user): int {
        Livewire::actingAs($user)->test('pages::results.index');
        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::actingAs($user)->test('pages::results.index');

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $seed(1);
    $small = $countQueries();

    $seed(10);

    expect($countQueries())->toBe($small);
});

describe('the create deep link (T14)', function () {
    beforeEach(function () {
        $this->program = Program::factory()->create(['name' => 'Water Security']);
        $this->deepLink = fn (User $user, array $query) => Livewire::withQueryParams($query)->actingAs($user)->test('pages::results.index');
    });

    test('?program=&create=1 opens the create form pre-filled with that program at impact level', function () {
        ($this->deepLink)(mealManager(), ['program' => (string) $this->program->id, 'create' => '1'])
            ->assertSet('showResultModal', true)
            ->assertSet('resultProgramId', (string) $this->program->id)
            ->assertSet('resultType', ResultType::Impact->value);
    });

    test('the form stays closed without results.create, for an unknown program, or when create is not 1', function (string $role, ?int $programId, string $create) {
        ($this->deepLink)(userWithRole($role), ['program' => (string) ($programId ?? $this->program->id), 'create' => $create])
            ->assertSet('showResultModal', false)
            ->assertSet('resultProgramId', '');
    })->with([
        'no results.create' => ['Financial', null, '1'],
        'unknown program' => ['MEAL', 999999, '1'],
        'create is not 1' => ['MEAL', null, '2'],
    ]);
});

test('a statement whose only reading is old is waiting for a measurement, never on track', function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-30 12:00:00'));

    $user = User::factory()->role('General Manager')->create();

    $old = Result::factory()->create(['statement' => 'Read long ago.']);
    $fresh = Result::factory()->create(['statement' => 'Read this month.']);
    $never = Result::factory()->create(['statement' => 'Never read.']);

    $oldIndicator = Indicator::factory()->create(['result_id' => $old->id, 'baseline' => 0, 'target' => 100, 'frequency' => Period::Quarterly]);
    IndicatorProgress::factory()->create(['indicator_id' => $oldIndicator->id, 'value' => 100, 'date' => '2026-01-31']);

    $freshIndicator = Indicator::factory()->create(['result_id' => $fresh->id, 'baseline' => 0, 'target' => 100, 'frequency' => Period::Quarterly]);
    IndicatorProgress::factory()->create(['indicator_id' => $freshIndicator->id, 'value' => 100, 'date' => '2026-06-20']);

    Indicator::factory()->create(['result_id' => $never->id, 'frequency' => Period::Quarterly]);

    $component = Livewire::actingAs($user)->test('pages::results.index');

    expect($component->instance()->measurements()[$old->id]['status'])->toBe('waiting')
        ->and($component->instance()->measurements()[$fresh->id]['status'])->toBe('ontrack')
        ->and($component->instance()->measurements()[$never->id]['status'])->toBe('waiting');
});

test('the average achievement caps one over-target indicator at 100 percent', function () {
    $user = User::factory()->role('General Manager')->create();
    $result = Result::factory()->create();

    $over = Indicator::factory()->create(['result_id' => $result->id, 'baseline' => 0, 'target' => 10]);
    IndicatorProgress::factory()->create(['indicator_id' => $over->id, 'value' => 50]);

    $under = Indicator::factory()->create(['result_id' => $result->id, 'baseline' => 0, 'target' => 100]);
    IndicatorProgress::factory()->create(['indicator_id' => $under->id, 'value' => 50]);

    $component = Livewire::actingAs($user)->test('pages::results.index');

    expect($component->instance()->measurements()[$result->id]['achieved'])->toBe(75.0);
});

test('every program logframe and every statement starts folded', function () {
    $user = User::factory()->role('General Manager')->create();
    $program = Program::factory()->create();
    $impact = Result::factory()->type(ResultType::Impact)->create(['program_id' => $program->id]);
    Result::factory()->type(ResultType::Outcome)->create(['program_id' => $program->id, 'parent_id' => $impact->id]);

    Livewire::actingAs($user)->test('pages::results.index')
        ->assertSeeHtml('data-test="program-logframe-toggle-'.$program->id.'"')
        ->assertSeeHtml('x-data="{ open: false }"')
        ->assertSeeHtml('branchOpen: false')
        ->assertDontSeeHtml('branchOpen: true')
        ->assertDontSeeHtml('data-test="focus-chip-')
        ->assertDontSeeHtml('data-test="more-filters-button"')
        ->assertDontSeeHtml('data-test="results-view-tree"');
});
