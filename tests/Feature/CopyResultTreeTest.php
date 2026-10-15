<?php

use App\Actions\Results\CopyResultTree;
use App\Enums\ActivityKind;
use App\Enums\ResultType;
use App\Models\Indicator;
use App\Models\LibraryIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Result;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->program = Program::factory()->create();
    $this->proposal = copyTargetProposal($this->program);
    // results.create + proposal visibility: MEAL and Managers lack proposals.view (T12 Deviation 10).
    $this->editor = User::factory()->role('General Manager')->create();
});

/**
 * A proposal linked to one project in each of the given programs.
 */
function copyTargetProposal(Program ...$programs): Proposal
{
    $proposal = Proposal::factory()->create();

    foreach ($programs as $program) {
        $projectId = Project::factory()->create(['program_id' => $program->id])->id;
        DB::table('project_proposal')->insert(['project_id' => $projectId, 'proposal_id' => $proposal->id]);
    }

    return $proposal;
}

/**
 * A program-framework tree: impact → outcome → `$outputs` outputs, each with
 * an indicator carrying a default target; the first output has an activity.
 */
function programFrameworkTree(Program $program, int $outputs = 1): Result
{
    $impact = Result::factory()->type(ResultType::Impact)->create(['program_id' => $program->id]);
    $outcome = Result::factory()->type(ResultType::Outcome)->childOf($impact)->create(['program_id' => $program->id]);
    Indicator::factory()->create(['result_id' => $outcome->id, 'target' => 80, 'measure' => 'percentage']);

    for ($i = 0; $i < $outputs; $i++) {
        $output = Result::factory()->type(ResultType::Output)->childOf($outcome)->create(['program_id' => $program->id]);
        Indicator::factory()->create(['result_id' => $output->id, 'target' => 100 + $i]);

        if ($i === 0) {
            Result::factory()->activity($output)->create(['activity_kind' => ActivityKind::Event]);
        }
    }

    return $impact;
}

describe('copying a framework', function () {
    it('copies the subtree and its indicators, remapping the parents', function () {
        $library = LibraryIndicator::factory()->create();
        $source = programFrameworkTree($this->program, 2);
        $sourceIndicator = Indicator::query()->where('result_id', $source->descendants()->firstWhere('type', ResultType::Outcome)->id)->sole();
        $sourceIndicator->forceFill(['library_indicator_id' => $library->id, 'code' => $library->code])->save();

        $this->actingAs($this->editor);
        $root = app(CopyResultTree::class)->handle($source, $this->proposal);

        $copies = $this->proposal->results()->with('copiedFrom')->get();
        expect($copies)->toHaveCount(5)
            ->and($root->proposal_id)->toBe($this->proposal->id)
            ->and($root->parent_id)->toBeNull()
            ->and($root->copied_from_id)->toBe($source->id)
            ->and($root->contributes_to_result_id)->toBe($source->id)
            ->and($root->program_id)->toBe($this->program->id);

        $sourceIds = Result::query()->programFramework()->where('program_id', $this->program->id)->pluck('id');
        expect($copies->pluck('copied_from_id')->sort()->values()->all())->toBe($sourceIds->sort()->values()->all());

        foreach ($copies->where('parent_id', '!==', null) as $copy) {
            $parent = $copies->firstWhere('id', $copy->parent_id);
            expect($parent)->not->toBeNull()
                ->and($parent->copied_from_id)->toBe($copy->copiedFrom->parent_id)
                ->and($copy->program_id)->toBe($this->program->id)
                ->and($copy->proposal_id)->toBe($this->proposal->id);
        }

        expect($copies->firstWhere('type', ResultType::Activity)->activity_kind)->toBe(ActivityKind::Event);
    });

    it('copies indicators without their default target and records where they came from', function () {
        $library = LibraryIndicator::factory()->create();
        $source = programFrameworkTree($this->program);
        $outcome = $source->children()->sole();
        $original = Indicator::query()->where('result_id', $outcome->id)->sole();
        $original->forceFill(['library_indicator_id' => $library->id, 'code' => $library->code, 'unit' => 'persons'])->save();

        $this->actingAs($this->editor);
        app(CopyResultTree::class)->handle($source, $this->proposal);

        $copy = Indicator::query()->where('copied_from_id', $original->id)->sole();

        expect($copy)
            ->name->toBe($original->name)
            ->library_indicator_id->toBe($library->id)
            ->code->toBe($library->code)
            ->measure->toBe($original->measure)
            ->unit->toBe('persons')
            ->target->toBeNull()
            ->project_id->toBeNull()
            ->and($copy->result->proposal_id)->toBe($this->proposal->id)
            ->and(Indicator::query()->whereNotNull('copied_from_id')->count())->toBe(Indicator::query()->whereIn('result_id', $this->proposal->results()->pluck('id'))->count());
    });

    it('never copies targets or reports', function () {
        $source = programFrameworkTree($this->program);
        $targetsBefore = DB::table('plan_indicators')->count();
        $reportsBefore = DB::table('reports')->count();

        $this->actingAs($this->editor);
        app(CopyResultTree::class)->handle($source, $this->proposal);

        expect(DB::table('plan_indicators')->count())->toBe($targetsBefore)
            ->and(DB::table('reports')->count())->toBe($reportsBefore);
    });

    it('does not link a cross-cutting root to the program framework', function () {
        $source = Result::factory()->crossCutting()->create(['program_id' => $this->program->id]);
        Indicator::factory()->create(['result_id' => $source->id]);

        $this->actingAs($this->editor);
        $root = app(CopyResultTree::class)->handle($source, $this->proposal);

        expect($root)
            ->type->toBe(ResultType::CrossCutting)
            ->copied_from_id->toBe($source->id)
            ->contributes_to_result_id->toBeNull();
    });

    it('gives each program of a multi-program proposal one root, with no program of its own', function () {
        $other = Program::factory()->create();
        $proposal = copyTargetProposal($this->program, $other);
        $first = programFrameworkTree($this->program);
        $second = programFrameworkTree($other);

        $this->actingAs($this->editor);
        $copyOne = app(CopyResultTree::class)->handle($first, $proposal);
        $copyTwo = app(CopyResultTree::class)->handle($second, $proposal);

        expect($copyOne->program_id)->toBeNull()
            ->and($copyTwo->program_id)->toBeNull()
            ->and($proposal->results()->whereNull('parent_id')->count())->toBe(2)
            ->and($proposal->results()->whereNotNull('program_id')->count())->toBe(0);
    });
});

describe('what is refused', function () {
    it('refuses the same root twice', function () {
        $source = programFrameworkTree($this->program);
        $this->actingAs($this->editor);
        app(CopyResultTree::class)->handle($source, $this->proposal);
        $count = $this->proposal->results()->count();

        expect(fn () => app(CopyResultTree::class)->handle($source, $this->proposal))
            ->toThrow(ValidationException::class, __('results_framework.framework_already_imported'));
        expect($this->proposal->results()->count())->toBe($count);
    });

    it('refuses a root of a program the proposal does not belong to', function () {
        $foreign = programFrameworkTree(Program::factory()->create());
        $this->actingAs($this->editor);

        expect(fn () => app(CopyResultTree::class)->handle($foreign, $this->proposal))
            ->toThrow(ValidationException::class, __('results_framework.choose_a_framework_of_this_proposal'));
        expect($this->proposal->results()->count())->toBe(0);
    });

    it('refuses a node that is not a program-framework root', function () {
        $source = programFrameworkTree($this->program);
        $child = $source->children()->sole();
        $ownRoot = Result::factory()->type(ResultType::Impact)->create(['program_id' => $this->program->id, 'proposal_id' => $this->proposal->id]);
        $this->actingAs($this->editor);

        expect(fn () => app(CopyResultTree::class)->handle($child, $this->proposal))->toThrow(ValidationException::class);
        expect(fn () => app(CopyResultTree::class)->handle($ownRoot, $this->proposal))->toThrow(ValidationException::class);
        expect($this->proposal->results()->count())->toBe(1);
    });

    it('refuses once the proposal has been submitted unless the user holds results.update', function () {
        $source = programFrameworkTree($this->program);
        DB::table('proposals')->where('id', $this->proposal->id)->update(['status' => 'submitted']);
        $proposal = $this->proposal->fresh();

        $creatorOnly = User::factory()->role('Financial')->create();
        $creatorOnly->givePermissionTo('results.create');

        $this->actingAs($creatorOnly);
        expect(fn () => app(CopyResultTree::class)->handle($source, $proposal))
            ->toThrow(ValidationException::class, __('results_framework.structural_lock'));
        expect($proposal->results()->count())->toBe(0);

        $this->actingAs($this->editor);
        expect(app(CopyResultTree::class)->handle($source, $proposal)->proposal_id)->toBe($proposal->id);
    });

    it('refuses a user without results.create, and one who cannot see the proposal', function (string $role) {
        $source = programFrameworkTree($this->program);
        $this->actingAs(userWithRole($role));

        expect(fn () => app(CopyResultTree::class)->handle($source, $this->proposal))->toThrow(AuthorizationException::class);
        expect($this->proposal->results()->count())->toBe(0);
    })->with(['no results.create' => 'Team Member', 'no proposals.view' => 'MEAL', 'neither results.create nor proposals.view' => 'Financial']);
});

it('runs a query count that does not grow with the number of nodes', function () {
    $small = programFrameworkTree($this->program, 1);
    $bigProgram = Program::factory()->create();
    $big = programFrameworkTree($bigProgram, 28);
    $bigProposal = copyTargetProposal($bigProgram);

    $this->actingAs($this->editor);

    $count = function (Result $source, Proposal $proposal): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(CopyResultTree::class)->handle($source, $proposal);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    // Warm the permission and lookup caches first, so both runs start equal.
    $warmProgram = Program::factory()->create();
    app(CopyResultTree::class)->handle(programFrameworkTree($warmProgram), copyTargetProposal($warmProgram));

    expect($count($big, $bigProposal))->toBe($count($small, $this->proposal));
});

describe('the Logical Framework tab', function () {
    it('previews a root and copies it after confirmation', function () {
        $source = programFrameworkTree($this->program, 2);

        $tab = Livewire::actingAs($this->editor)->test('proposals.logframe', ['proposalId' => $this->proposal->id])
            ->assertSeeHtml('data-test="start-from-program-framework"')
            ->call('openCopyModal')
            ->assertSet('showCopyModal', true)
            ->set('copySourceId', $source->id)
            ->assertSeeHtml('data-test="copy-preview"')
            ->call('askCopyConfirmation')
            ->assertSet('showCopyConfirmModal', true)
            ->call('copyFramework')
            ->assertHasNoErrors()
            ->assertSet('showCopyConfirmModal', false);

        expect($this->proposal->results()->count())->toBe(5);
        $tab->assertDontSeeHtml('data-test="copy-source-option-'.$source->id.'"');
    });

    it('offers only the roots of the proposal programs and rejects any other id', function () {
        $own = programFrameworkTree($this->program);
        $foreign = programFrameworkTree(Program::factory()->create());

        $tab = Livewire::actingAs($this->editor)->test('proposals.logframe', ['proposalId' => $this->proposal->id])
            ->call('openCopyModal');

        expect($tab->instance()->copySources->pluck('id')->all())->toBe([$own->id]);

        $tab->set('copySourceId', $foreign->id)->call('askCopyConfirmation')->call('copyFramework')->assertHasErrors('copySourceId');
        expect($this->proposal->results()->count())->toBe(0);
    });

    it('hides the button from a user who cannot create results', function () {
        Livewire::actingAs(userWithRole('Team Member'))->test('proposals.logframe', ['proposalId' => $this->proposal->id])
            ->assertDontSeeHtml('data-test="start-from-program-framework"');
    });
});
