<?php

use App\Actions\Results\DeleteResult;
use App\Actions\Results\SaveResult;
use App\Enums\ResultType;
use App\Exceptions\InvalidParentException;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Result;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->role('MEAL')->create());
    $this->program = Program::factory()->create();
});

/**
 * The validation messages a refused framework write raised, keyed by field.
 *
 * @return array<string, list<string>>
 */
function resultScopeRefusal(Closure $call): array
{
    try {
        $call();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    throw new RuntimeException('The call was not refused.');
}

/**
 * Save a result through the action.
 *
 * @param  array<string, mixed>  $input
 */
function saveFrameworkResult(array $input, ?Proposal $proposal = null, ?Result $existing = null): Result
{
    return app(SaveResult::class)->handle($existing, [
        'statement' => 'Improved access to safe drinking water.',
        'checkout_period' => 'quarterly',
        ...$input,
    ], $proposal);
}

/**
 * A proposal linked to one project in each of the given programs.
 */
function proposalInPrograms(Program ...$programs): Proposal
{
    $proposal = Proposal::factory()->create();

    foreach ($programs as $program) {
        $projectId = Project::factory()->create(['program_id' => $program->id])->id;
        DB::table('project_proposal')->insert(['project_id' => $projectId, 'proposal_id' => $proposal->id]);
    }

    return $proposal;
}

/**
 * Store a proposal status straight in the column, as data already on file.
 */
function fileProposalStatus(Proposal $proposal, string $status): void
{
    DB::table('proposals')->where('id', $proposal->id)->update(['status' => $status]);
}

test('an activity sits under an output', function () {
    $output = saveFrameworkResult(['type' => 'output', 'program_id' => $this->program->id]);

    $activity = saveFrameworkResult(['type' => 'activity', 'parent_id' => $output->id]);

    expect($activity->type)->toBe(ResultType::Activity)
        ->and($activity->parent_id)->toBe($output->id)
        ->and($activity->program_id)->toBe($this->program->id);
});

test('an activity under an outcome, or with no parent, is refused', function () {
    $outcome = saveFrameworkResult(['type' => 'outcome', 'program_id' => $this->program->id]);

    expect(resultScopeRefusal(fn () => saveFrameworkResult(['type' => 'activity', 'parent_id' => $outcome->id])))
        ->toBe(['parent_id' => [__('results_framework.activity_under_output')]])
        ->and(resultScopeRefusal(fn () => saveFrameworkResult(['type' => 'activity'])))
        ->toBe(['parent_id' => [__('results_framework.activity_under_output')]]);
});

test('nothing sits under an activity', function () {
    $output = saveFrameworkResult(['type' => 'output']);
    $activity = saveFrameworkResult(['type' => 'activity', 'parent_id' => $output->id]);

    expect(resultScopeRefusal(fn () => saveFrameworkResult(['type' => 'activity', 'parent_id' => $activity->id])))
        ->toHaveKey('parent_id')
        ->and(fn () => Result::factory()->type(ResultType::Activity)->create(['parent_id' => $activity->id]))
        ->toThrow(InvalidParentException::class);
});

test('a cross-cutting result is a root with no children', function () {
    $impact = saveFrameworkResult(['type' => 'impact']);
    $crossCutting = saveFrameworkResult(['type' => 'cross-cutting']);

    expect($crossCutting->parent_id)->toBeNull()
        ->and(resultScopeRefusal(fn () => saveFrameworkResult(['type' => 'cross-cutting', 'parent_id' => $impact->id])))
        ->toBe(['parent_id' => [__('results_framework.cross_cutting_root')]])
        ->and(resultScopeRefusal(fn () => saveFrameworkResult(['type' => 'outcome', 'parent_id' => $crossCutting->id])))
        ->toBe(['parent_id' => [__('results_framework.cross_cutting_root')]])
        ->and(fn () => Result::factory()->type(ResultType::Outcome)->create(['parent_id' => $crossCutting->id]))
        ->toThrow(InvalidParentException::class);
});

test('a result with children cannot become cross-cutting', function () {
    $impact = saveFrameworkResult(['type' => 'impact']);
    saveFrameworkResult(['type' => 'outcome', 'parent_id' => $impact->id]);

    expect(resultScopeRefusal(fn () => saveFrameworkResult(['type' => 'cross-cutting'], existing: $impact)))
        ->toBe(['type' => [__('results_framework.cross_cutting_root')]]);
});

test('an output with activities cannot change level', function () {
    $output = saveFrameworkResult(['type' => 'output']);
    saveFrameworkResult(['type' => 'activity', 'parent_id' => $output->id]);

    expect(resultScopeRefusal(fn () => saveFrameworkResult(['type' => 'outcome'], existing: $output)))
        ->toBe(['type' => [__('results_framework.nested_results_still_sit_at_the_level_below')]]);
});

test('a child takes its parent program, never the one sent', function () {
    $other = Program::factory()->create();
    $impact = saveFrameworkResult(['type' => 'impact', 'program_id' => $this->program->id]);

    $outcome = saveFrameworkResult(['type' => 'outcome', 'parent_id' => $impact->id, 'program_id' => $other->id]);

    expect($outcome->program_id)->toBe($this->program->id);
});

test('a parent from another framework is refused', function () {
    $proposal = proposalInPrograms($this->program);
    $otherProposal = proposalInPrograms($this->program);
    $foreignImpact = saveFrameworkResult(['type' => 'impact'], $otherProposal);
    $programImpact = saveFrameworkResult(['type' => 'impact', 'program_id' => $this->program->id]);
    $proposalImpact = saveFrameworkResult(['type' => 'impact'], $proposal);

    expect(resultScopeRefusal(fn () => saveFrameworkResult(['type' => 'outcome', 'parent_id' => $foreignImpact->id], $proposal)))
        ->toBe(['parent_id' => [__('results_framework.other_framework')]])
        ->and(resultScopeRefusal(fn () => saveFrameworkResult(['type' => 'outcome', 'parent_id' => $programImpact->id], $proposal)))
        ->toBe(['parent_id' => [__('results_framework.other_framework')]])
        ->and(resultScopeRefusal(fn () => saveFrameworkResult(['type' => 'outcome', 'parent_id' => $proposalImpact->id])))
        ->toBe(['parent_id' => [__('results_framework.other_framework')]]);
});

test('the observer refuses a child stored in another framework', function () {
    $proposal = proposalInPrograms($this->program);
    $impact = saveFrameworkResult(['type' => 'impact'], $proposal);

    expect(fn () => Result::factory()->type(ResultType::Outcome)->create(['parent_id' => $impact->id]))
        ->toThrow(InvalidParentException::class)
        ->and(fn () => Result::factory()->type(ResultType::Outcome)->create([
            'parent_id' => $impact->id,
            'proposal_id' => $proposal->id,
            'program_id' => Program::factory()->create()->id,
        ]))
        ->toThrow(InvalidParentException::class);
});

test('a parent removed meanwhile asks for another parent', function () {
    $impact = saveFrameworkResult(['type' => 'impact']);
    $impactId = $impact->id;
    $impact->delete();

    expect(resultScopeRefusal(fn () => saveFrameworkResult(['type' => 'outcome', 'parent_id' => $impactId])))
        ->toBe(['parent_id' => [__('results_framework.parent_gone')]]);
});

test('a proposal root takes the shared program of its projects', function () {
    $single = saveFrameworkResult(['type' => 'impact', 'program_id' => Program::factory()->create()->id], proposalInPrograms($this->program, $this->program));
    $multi = saveFrameworkResult(['type' => 'impact'], proposalInPrograms($this->program, Program::factory()->create()));

    expect($single->program_id)->toBe($this->program->id)
        ->and($single->proposal_id)->not->toBeNull()
        ->and($multi->program_id)->toBeNull();
});

test('a proposal child carries its proposal and program', function () {
    $proposal = proposalInPrograms($this->program);
    $impact = saveFrameworkResult(['type' => 'impact'], $proposal);

    $outcome = saveFrameworkResult(['type' => 'outcome', 'parent_id' => $impact->id], $proposal);

    expect($outcome->proposal_id)->toBe($proposal->id)
        ->and($outcome->program_id)->toBe($this->program->id);
});

test('a proposal root contributes to a result of one of its programs', function () {
    $proposal = proposalInPrograms($this->program);
    $programOutcome = saveFrameworkResult(['type' => 'outcome', 'program_id' => $this->program->id]);

    $root = saveFrameworkResult(['type' => 'impact', 'contributes_to_result_id' => $programOutcome->id], $proposal);

    expect($root->contributes_to_result_id)->toBe($programOutcome->id);
});

test('contributes-to is refused from a non-root, to a proposal node, or outside the programs', function () {
    $proposal = proposalInPrograms($this->program);
    $programOutcome = saveFrameworkResult(['type' => 'outcome', 'program_id' => $this->program->id]);
    $outsideOutcome = saveFrameworkResult(['type' => 'outcome', 'program_id' => Program::factory()->create()->id]);
    $root = saveFrameworkResult(['type' => 'impact'], $proposal);
    $otherProposalNode = saveFrameworkResult(['type' => 'impact'], proposalInPrograms($this->program));
    $message = ['contributes_to_result_id' => [__('results_framework.contributes_to')]];

    expect(resultScopeRefusal(fn () => saveFrameworkResult(['type' => 'outcome', 'parent_id' => $root->id, 'contributes_to_result_id' => $programOutcome->id], $proposal)))
        ->toBe($message)
        ->and(resultScopeRefusal(fn () => saveFrameworkResult(['type' => 'impact', 'contributes_to_result_id' => $otherProposalNode->id], $proposal)))
        ->toBe($message)
        ->and(resultScopeRefusal(fn () => saveFrameworkResult(['type' => 'impact', 'contributes_to_result_id' => $outsideOutcome->id], $proposal)))
        ->toBe($message)
        ->and(resultScopeRefusal(fn () => saveFrameworkResult(['type' => 'impact', 'contributes_to_result_id' => $programOutcome->id])))
        ->toBe($message);
});

test('editing a node through another proposal is not found', function () {
    $proposal = proposalInPrograms($this->program);
    $impact = saveFrameworkResult(['type' => 'impact'], $proposal);

    expect(fn () => saveFrameworkResult(['type' => 'impact'], proposalInPrograms($this->program), $impact))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(DeleteResult::class)->handle($impact, proposalInPrograms($this->program)))
        ->toThrow(ModelNotFoundException::class);
});

test('proposals.update alone cannot write the framework', function () {
    $proposal = proposalInPrograms($this->program);
    $impact = saveFrameworkResult(['type' => 'impact'], $proposal);
    $this->actingAs(User::factory()->role('Financial')->create());

    expect(fn () => saveFrameworkResult(['type' => 'impact'], $proposal))->toThrow(AuthorizationException::class)
        ->and(fn () => saveFrameworkResult(['type' => 'outcome'], $proposal, $impact))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeleteResult::class)->handle($impact, $proposal))->toThrow(AuthorizationException::class);

    expect(Result::query()->count())->toBe(1);
});

test('the framework is editable at concept note by a results.create holder', function () {
    $proposal = proposalInPrograms($this->program);
    $this->actingAs(tap(User::factory()->create())->givePermissionTo('results.create'));

    expect(saveFrameworkResult(['type' => 'impact'], $proposal)->exists)->toBeTrue();
});

test('after submission only results.update holders change the structure', function () {
    $proposal = proposalInPrograms($this->program);
    $impact = saveFrameworkResult(['type' => 'impact'], $proposal);
    fileProposalStatus($proposal, 'submitted');
    $proposal->refresh();
    $this->actingAs(tap(User::factory()->create())->givePermissionTo('results.create', 'results.delete'));
    $lock = ['structure' => [__('results_framework.structural_lock')]];

    expect(resultScopeRefusal(fn () => saveFrameworkResult(['type' => 'outcome', 'parent_id' => $impact->id], $proposal)))->toBe($lock)
        ->and(resultScopeRefusal(fn () => app(DeleteResult::class)->handle($impact, $proposal)))->toBe($lock);

    $this->actingAs(User::factory()->role('MEAL')->create());

    expect(saveFrameworkResult(['type' => 'outcome', 'parent_id' => $impact->id], $proposal)->exists)->toBeTrue();
});

test('a held proposal keeps the lock of the status it was held from', function () {
    $proposal = proposalInPrograms($this->program);
    $this->actingAs(tap(User::factory()->create())->givePermissionTo('results.create'));

    DB::table('proposal_status_notes')->insert([
        ['proposal_id' => $proposal->id, 'status' => 'concept note submitted', 'context' => 'Sent.', 'date' => '2026-01-01', 'created_at' => now(), 'updated_at' => now()],
        ['proposal_id' => $proposal->id, 'status' => 'on hold', 'context' => 'Waiting.', 'date' => '2026-01-02', 'created_at' => now(), 'updated_at' => now()],
    ]);
    fileProposalStatus($proposal, 'on hold');

    expect(saveFrameworkResult(['type' => 'impact'], $proposal->refresh())->exists)->toBeTrue();

    DB::table('proposal_status_notes')->insert(['proposal_id' => $proposal->id, 'status' => 'submitted', 'context' => 'Sent.', 'date' => '2026-01-03', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('proposal_status_notes')->insert(['proposal_id' => $proposal->id, 'status' => 'on hold', 'context' => 'Waiting.', 'date' => '2026-01-04', 'created_at' => now(), 'updated_at' => now()]);

    expect(resultScopeRefusal(fn () => saveFrameworkResult(['type' => 'impact'], $proposal->refresh())))
        ->toBe(['structure' => [__('results_framework.structural_lock')]]);
});

test('a result with children or indicators is not deleted, and a gone one returns quietly', function () {
    $impact = saveFrameworkResult(['type' => 'impact']);
    $outcome = saveFrameworkResult(['type' => 'outcome', 'parent_id' => $impact->id]);
    insertIndicator($outcome->id);

    expect(resultScopeRefusal(fn () => app(DeleteResult::class)->handle($impact)))->toHaveKey('result')
        ->and(resultScopeRefusal(fn () => app(DeleteResult::class)->handle($outcome)))->toHaveKey('result');

    $leaf = saveFrameworkResult(['type' => 'outcome', 'parent_id' => $impact->id]);
    $stale = Result::query()->findOrFail($leaf->id);
    app(DeleteResult::class)->handle($leaf);
    app(DeleteResult::class)->handle($stale);

    $this->assertDatabaseMissing('results', ['id' => $leaf->id]);
});
