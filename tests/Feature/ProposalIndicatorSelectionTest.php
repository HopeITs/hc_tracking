<?php

use App\Actions\Targets\AttachLibraryIndicatorToResult;
use App\Actions\Targets\DetachIndicatorFromResult;
use App\Enums\CollectionFrequency;
use App\Enums\IndicatorMeasure;
use App\Enums\Period;
use App\Enums\ResultType;
use App\Models\Indicator;
use App\Models\LibraryIndicator;
use App\Models\PlanIndicator;
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
    $this->proposal = Proposal::factory()->create();
    $this->project = Project::factory()->create(['program_id' => $this->program->id]);
    DB::table('project_proposal')->insert(['project_id' => $this->project->id, 'proposal_id' => $this->proposal->id]);

    $this->output = Result::factory()->create([
        'type' => ResultType::Output,
        'program_id' => $this->program->id,
        'proposal_id' => $this->proposal->id,
    ]);
});

/**
 * The validation messages a refused selection raised, keyed by field.
 *
 * @return array<string, list<string>>
 */
function selectionRefusal(Closure $call): array
{
    try {
        $call();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    throw new RuntimeException('The call was not refused.');
}

function attachEntry(Proposal $proposal, int $libraryIndicatorId, int $resultId): Indicator
{
    return app(AttachLibraryIndicatorToResult::class)->handle($proposal, $libraryIndicatorId, $resultId);
}

test('attaching creates an instance under the node, linked to the library', function () {
    $entry = LibraryIndicator::factory()->percentage()->create([
        'program_id' => $this->program->id,
        'code' => '002.OP1.1',
        'frequency' => CollectionFrequency::Quarterly,
        'suggested_level' => ResultType::Output,
    ]);

    $indicator = attachEntry($this->proposal, $entry->id, $this->output->id);

    $row = DB::table('indicators')->find($indicator->id);
    expect($row->result_id)->toBe($this->output->id)
        ->and($row->library_indicator_id)->toBe($entry->id)
        ->and($row->code)->toBe('002.OP1.1')
        ->and($row->name)->toBe($entry->name_ar)
        ->and($row->measure)->toBe(IndicatorMeasure::Percentage->value)
        ->and($row->frequency)->toBe(Period::Quarterly->value);
});

test('a frequency with no calendar period falls back to the column default', function () {
    $entry = LibraryIndicator::factory()->create(['program_id' => $this->program->id, 'frequency' => CollectionFrequency::PerEvent]);

    $indicator = attachEntry($this->proposal, $entry->id, $this->output->id);

    expect(DB::table('indicators')->where('id', $indicator->id)->value('frequency'))->toBe(Period::Quarterly->value);
});

test('an institutional entry is accepted', function () {
    $entry = LibraryIndicator::factory()->create(['program_id' => null]);

    expect(attachEntry($this->proposal, $entry->id, $this->output->id)->library_indicator_id)->toBe($entry->id);
});

test('an entry of another program is refused', function () {
    $entry = LibraryIndicator::factory()->create(['program_id' => Program::factory()->create()->id]);

    expect(selectionRefusal(fn () => attachEntry($this->proposal, $entry->id, $this->output->id)))
        ->toHaveKey('library_indicator', [__('targets.entry_outside_proposal_programs')]);
    expect(DB::table('indicators')->count())->toBe(0);
});

test('an inactive entry is refused', function () {
    $entry = LibraryIndicator::factory()->inactive()->create(['program_id' => $this->program->id]);

    expect(selectionRefusal(fn () => attachEntry($this->proposal, $entry->id, $this->output->id)))
        ->toHaveKey('library_indicator', [__('targets.entry_inactive')]);
});

test('a node at a level other than the suggested one is refused', function () {
    $entry = LibraryIndicator::factory()->create(['program_id' => $this->program->id, 'suggested_level' => ResultType::Outcome]);

    expect(selectionRefusal(fn () => attachEntry($this->proposal, $entry->id, $this->output->id)))
        ->toHaveKey('result', [__('targets.level_incompatible', ['level' => ResultType::Outcome->label()])]);
});

test('a result of another proposal is not found', function () {
    $entry = LibraryIndicator::factory()->create(['program_id' => $this->program->id]);
    $foreign = Result::factory()->create(['type' => ResultType::Output, 'program_id' => $this->program->id, 'proposal_id' => Proposal::factory()->create()->id]);

    attachEntry($this->proposal, $entry->id, $foreign->id);
})->throws(ModelNotFoundException::class);

test('a program framework result is not found', function () {
    $entry = LibraryIndicator::factory()->create(['program_id' => $this->program->id]);
    $programNode = Result::factory()->create(['type' => ResultType::Output, 'program_id' => $this->program->id]);

    attachEntry($this->proposal, $entry->id, $programNode->id);
})->throws(ModelNotFoundException::class);

test('the same entry twice under one node is refused as already attached', function () {
    $entry = LibraryIndicator::factory()->create(['program_id' => $this->program->id]);
    attachEntry($this->proposal, $entry->id, $this->output->id);

    expect(selectionRefusal(fn () => attachEntry($this->proposal, $entry->id, $this->output->id)))
        ->toHaveKey('library_indicator', [__('targets.already_attached')]);
    expect(DB::table('indicators')->count())->toBe(1);
});

test('a terminal proposal is read-only', function () {
    $entry = LibraryIndicator::factory()->create(['program_id' => $this->program->id]);
    DB::table('proposals')->where('id', $this->proposal->id)->update(['status' => 'rejected']);

    expect(selectionRefusal(fn () => attachEntry($this->proposal->fresh(), $entry->id, $this->output->id)))
        ->toHaveKey('proposal', [__('targets.proposal_read_only')]);
});

test('attaching needs indicators.create', function () {
    $entry = LibraryIndicator::factory()->create(['program_id' => $this->program->id]);
    $this->actingAs(User::factory()->role('Financial')->create());

    attachEntry($this->proposal, $entry->id, $this->output->id);
})->throws(AuthorizationException::class);

test('detaching an instance without targets deletes it', function () {
    $entry = LibraryIndicator::factory()->create(['program_id' => $this->program->id]);
    $indicator = attachEntry($this->proposal, $entry->id, $this->output->id);

    app(DetachIndicatorFromResult::class)->handle($indicator);

    expect(DB::table('indicators')->find($indicator->id))->toBeNull();
});

test('detaching an instance with targets is refused with the count', function () {
    $entry = LibraryIndicator::factory()->create(['program_id' => $this->program->id]);
    $indicator = attachEntry($this->proposal, $entry->id, $this->output->id);
    PlanIndicator::factory()->count(2)->sequence(
        ['project_id' => $this->project->id],
        ['project_id' => Project::factory()->create(['program_id' => $this->program->id])->id],
    )->create(['indicator_id' => $indicator->id, 'source_type' => 'proposal', 'source_id' => $this->proposal->id]);

    expect(selectionRefusal(fn () => app(DetachIndicatorFromResult::class)->handle($indicator)))
        ->toHaveKey('indicator', [trans_choice('targets.detach_has_targets', 2, ['count' => 2])]);
    expect(DB::table('indicators')->find($indicator->id))->not->toBeNull();
});

test('detaching needs indicators.delete', function () {
    $entry = LibraryIndicator::factory()->create(['program_id' => $this->program->id]);
    $indicator = attachEntry($this->proposal, $entry->id, $this->output->id);
    $this->actingAs(User::factory()->role('Managers')->create());

    app(DetachIndicatorFromResult::class)->handle($indicator);
})->throws(AuthorizationException::class);
