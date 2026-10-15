<?php

use App\Enums\ResultType;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Result;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->program = Program::factory()->create();
    $this->proposal = logframeProposal($this->program);
});

/**
 * A proposal linked to one project in each of the given programs.
 */
function logframeProposal(Program ...$programs): Proposal
{
    $proposal = Proposal::factory()->create();

    foreach ($programs as $program) {
        $projectId = Project::factory()->create(['program_id' => $program->id])->id;
        DB::table('project_proposal')->insert(['project_id' => $projectId, 'proposal_id' => $proposal->id]);
    }

    return $proposal;
}

/**
 * The logframe tab of the given proposal, as the given user.
 */
function logframeTab(User $user, Proposal $proposal): Testable
{
    return Livewire::actingAs($user)->test('proposals.logframe', ['proposalId' => $proposal->id]);
}

/**
 * A result in the proposal's framework, stored straight through the factory.
 */
function logframeNode(Proposal $proposal, ResultType $type, ?Result $parent = null, ?int $programId = null): Result
{
    return Result::factory()->type($type)->create([
        'proposal_id' => $proposal->id,
        'parent_id' => $parent?->id,
        'program_id' => $parent?->program_id ?? $programId,
    ]);
}

test('the proposal page renders the logframe tab', function () {
    $user = User::factory()->role('Financial')->create();

    Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $this->proposal])
        ->set('tab', 'logframe')
        ->assertSeeLivewire('proposals.logframe');
});

test('MEAL builds the four levels and links the root to the program framework', function () {
    $meal = User::factory()->role('MEAL')->create();
    $programImpact = Result::factory()->type(ResultType::Impact)->create(['program_id' => $this->program->id]);

    $tab = logframeTab($meal, $this->proposal)
        ->call('openCreateModal')
        ->set('statement', 'Families in the district are food secure.')
        ->set('resultType', 'impact')
        ->set('contributesToId', (string) $programImpact->id)
        ->call('saveResult')
        ->assertHasNoErrors();

    $impact = $this->proposal->results()->where('type', 'impact')->sole();

    $tab->call('openCreateModal', $impact->id)
        ->assertSet('resultType', 'outcome')
        ->set('statement', 'Households grow more of their own food.')
        ->call('saveResult')
        ->assertHasNoErrors();

    $outcome = $this->proposal->results()->where('type', 'outcome')->sole();

    $tab->call('openCreateModal', $outcome->id)
        ->assertSet('resultType', 'output')
        ->set('statement', 'Farmers trained in drip irrigation.')
        ->call('saveResult')
        ->assertHasNoErrors();

    $output = $this->proposal->results()->where('type', 'output')->sole();

    $tab->call('openCreateModal', $output->id)
        ->assertSet('resultType', 'activity')
        ->set('statement', 'Run four training sessions.')
        ->call('saveResult')
        ->assertHasNoErrors()
        ->assertSee('Run four training sessions.');

    $activity = $this->proposal->results()->where('type', 'activity')->sole();

    expect($impact->contributes_to_result_id)->toBe($programImpact->id)
        ->and($impact->program_id)->toBe($this->program->id)
        ->and($activity->parent_id)->toBe($output->id)
        ->and($activity->program_id)->toBe($this->program->id);

    $tab->assertSeeHtml('data-test="add-child-result-'.$output->id.'"')
        ->assertDontSeeHtml('data-test="add-child-result-'.$activity->id.'"');
});

test('the contributes-to picker offers only program-framework results of the proposal programs', function () {
    $meal = User::factory()->role('MEAL')->create();
    $ownProgramResult = Result::factory()->type(ResultType::Outcome)->create(['program_id' => $this->program->id]);
    $otherProgramResult = Result::factory()->type(ResultType::Outcome)->create(['program_id' => Program::factory()->create()->id]);
    $otherProposalRoot = logframeNode(logframeProposal($this->program), ResultType::Impact, programId: $this->program->id);

    $options = logframeTab($meal, $this->proposal)
        ->call('openCreateModal')
        ->set('resultType', 'impact')
        ->instance()
        ->contributesToOptions
        ->modelKeys();

    expect($options)->toBe([$ownProgramResult->id])
        ->not->toContain($otherProgramResult->id)
        ->not->toContain($otherProposalRoot->id);
});

test('Managers create and edit the framework but cannot delete from it', function () {
    $manager = User::factory()->role('Managers')->create();
    $impact = logframeNode($this->proposal, ResultType::Impact, programId: $this->program->id);

    logframeTab($manager, $this->proposal)
        ->call('openCreateModal', $impact->id)
        ->set('statement', 'Households grow more of their own food.')
        ->call('saveResult')
        ->assertHasNoErrors()
        ->call('openEditModal', $impact->id)
        ->set('statement', 'Families are food secure.')
        ->call('saveResult')
        ->assertHasNoErrors()
        ->assertDontSeeHtml('data-test="delete-result-');

    expect($impact->refresh()->statement)->toBe('Families are food secure.');

    logframeTab($manager, $this->proposal)->call('confirmDelete', $impact->id)->assertForbidden();
});

test('a proposal editor without results permissions cannot write the framework', function () {
    $financial = User::factory()->role('Financial')->create();
    $impact = logframeNode($this->proposal, ResultType::Impact, programId: $this->program->id);
    $tab = fn () => logframeTab($financial, $this->proposal);

    $tab()->call('openCreateModal')->assertForbidden();
    $tab()->call('openEditModal', $impact->id)->assertForbidden();
    $tab()->call('saveResult')->assertForbidden();
    $tab()->call('confirmDelete', $impact->id)->assertForbidden();
    $tab()->call('deleteResult')->assertForbidden();

    $tab()->assertSee($impact->statement)
        ->assertDontSeeHtml('data-test="add-child-result-')
        ->assertDontSeeHtml('data-test="edit-result-')
        ->assertDontSeeHtml('data-test="create-logframe-result"');

    expect(Result::query()->count())->toBe(1);
});

test('another proposal\'s result cannot be reached from this tab', function () {
    $meal = User::factory()->role('MEAL')->create();
    $foreignOutcome = logframeNode(logframeProposal($this->program), ResultType::Outcome, programId: $this->program->id);
    $tab = fn () => logframeTab($meal, $this->proposal);

    $tab()->call('openCreateModal', $foreignOutcome->id)->assertNotFound();
    $tab()->call('openEditModal', $foreignOutcome->id)->assertNotFound();
    $tab()->call('confirmDelete', $foreignOutcome->id)->assertNotFound();

    $tab()->call('openCreateModal')
        ->set('statement', 'Farmers trained in drip irrigation.')
        ->set('resultType', 'output')
        ->set('parentId', (string) $foreignOutcome->id)
        ->call('saveResult')
        ->assertHasErrors(['parentId' => __('results_framework.other_framework')]);

    expect($this->proposal->results()->count())->toBe(0);
});

test('the framework is editable at the concept note stage', function () {
    $meal = User::factory()->role('MEAL')->create();

    expect($this->proposal->refresh()->status->value)->toBe('concept note');

    logframeTab($meal, $this->proposal)
        ->assertSeeHtml('data-test="create-logframe-result"')
        ->assertDontSee(__('results_framework.locked_read_only'))
        ->call('openCreateModal')
        ->set('statement', 'Families are food secure.')
        ->set('resultType', 'impact')
        ->call('saveResult')
        ->assertHasNoErrors();

    expect($this->proposal->results()->count())->toBe(1);
});

test('after submission only results.update holders change the structure', function () {
    DB::table('proposals')->where('id', $this->proposal->id)->update(['status' => 'submitted']);
    $impact = logframeNode($this->proposal, ResultType::Impact, programId: $this->program->id);
    $creator = User::factory()->create();
    $creator->givePermissionTo('results.create');

    logframeTab($creator, $this->proposal)
        ->assertSee(__('results_framework.locked_read_only'))
        ->assertDontSeeHtml('data-test="create-logframe-result"')
        ->assertDontSeeHtml('data-test="add-child-result-'.$impact->id.'"')
        ->call('openCreateModal')
        ->set('statement', 'Households grow more of their own food.')
        ->set('resultType', 'outcome')
        ->set('parentId', (string) $impact->id)
        ->call('saveResult')
        ->assertHasErrors(['statement' => __('results_framework.structural_lock')]);

    logframeTab(User::factory()->role('MEAL')->create(), $this->proposal)
        ->assertDontSee(__('results_framework.locked_read_only'))
        ->call('openCreateModal', $impact->id)
        ->set('statement', 'Households grow more of their own food.')
        ->call('saveResult')
        ->assertHasNoErrors();

    expect($this->proposal->results()->count())->toBe(2);
});

test('cross-cutting results render as their own group below the tree', function () {
    $viewer = User::factory()->role('Team Member')->create();
    logframeNode($this->proposal, ResultType::Impact, programId: $this->program->id);
    $crossCutting = logframeNode($this->proposal, ResultType::CrossCutting, programId: $this->program->id);

    logframeTab($viewer, $this->proposal)
        ->assertSeeHtml('data-test="logframe-cross-cutting"')
        ->assertSeeInOrder(['data-test="logframe-tree"', 'data-test="logframe-cross-cutting"', $crossCutting->statement], false);
});

test('a proposal spanning programs shows the multi-program badge', function () {
    $viewer = User::factory()->role('Team Member')->create();

    logframeTab($viewer, $this->proposal)->assertDontSee(__('results_framework.multi_program'));
    logframeTab($viewer, logframeProposal($this->program, Program::factory()->create()))
        ->assertSee(__('results_framework.multi_program'));
});

test('the tab holds a flat query count whatever the framework size', function () {
    $user = User::factory()->role('MEAL')->create();
    $programImpact = Result::factory()->type(ResultType::Impact)->create(['program_id' => $this->program->id]);

    $seed = function (int $outputs) use ($programImpact): void {
        $impact = logframeNode($this->proposal, ResultType::Impact, programId: $this->program->id);
        $impact->forceFill(['contributes_to_result_id' => $programImpact->id])->save();
        $outcome = logframeNode($this->proposal, ResultType::Outcome, $impact);

        foreach (range(1, $outputs) as $index) {
            $output = logframeNode($this->proposal, ResultType::Output, $outcome);
            logframeNode($this->proposal, ResultType::Activity, $output);
            insertIndicator($output->id);
        }

        Result::factory()->type(ResultType::Outcome)->create(['program_id' => $this->program->id]);
    };

    $countQueries = function () use ($user): array {
        // The first render warms the permission cache; count the second.
        logframeTab($user, $this->proposal);
        DB::flushQueryLog();
        DB::enableQueryLog();

        logframeTab($user, $this->proposal);

        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        return $queries;
    };

    $seed(1);
    $small = $countQueries();

    $seed(14);
    $large = $countQueries();

    expect(count($large))->toBe(count($small))
        ->and(collect($large)->filter(fn (string $sql): bool => str_contains($sql, 'amount'))->all())->toBe([]);
});
