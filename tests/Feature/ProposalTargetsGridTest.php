<?php

use App\Actions\Targets\SaveProposalTargetsGrid;
use App\Enums\DonorAttribution;
use App\Enums\IndicatorMeasure;
use App\Enums\ResultType;
use App\Models\Indicator;
use App\Models\LibraryIndicator;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Result;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->role('MEAL')->create());

    $this->program = Program::factory()->create();
    $this->proposal = Proposal::factory()->create();
    $this->centerA = Project::factory()->create(['program_id' => $this->program->id]);
    $this->centerB = Project::factory()->create(['program_id' => $this->program->id]);
    DB::table('project_proposal')->insert([
        ['project_id' => $this->centerA->id, 'proposal_id' => $this->proposal->id, 'target_beneficiaries' => 180],
        ['project_id' => $this->centerB->id, 'proposal_id' => $this->proposal->id, 'target_beneficiaries' => null],
    ]);

    $this->output = Result::factory()->create(['type' => ResultType::Output, 'program_id' => $this->program->id, 'proposal_id' => $this->proposal->id]);
    $this->indicator = gridIndicator($this->output);
});

function gridIndicator(Result $result, IndicatorMeasure $measure = IndicatorMeasure::Numeric, bool $linked = true): Indicator
{
    $entry = LibraryIndicator::factory()->create(['measure' => $measure]);

    return Indicator::factory()->create([
        'result_id' => $result->id,
        'library_indicator_id' => $linked ? $entry->id : null,
        'measure' => $measure,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function gridCell(Indicator $indicator, Project $project, mixed $value, array $overrides = []): array
{
    return ['indicator_id' => $indicator->id, 'project_id' => $project->id, 'value' => $value, 'period' => $value === null ? null : 'annual', 'version' => null, ...$overrides];
}

/**
 * @param  list<array<string, mixed>>  $cells
 * @return array{created: int, updated: int, deleted: int}
 */
function saveGrid(Proposal $proposal, array $cells): array
{
    return app(SaveProposalTargetsGrid::class)->handle($proposal, $cells);
}

/**
 * @return array<string, list<string>>
 */
function gridRefusal(Closure $call): array
{
    try {
        $call();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    throw new RuntimeException('The call was not refused.');
}

function proposalTargets(Proposal $proposal): int
{
    return DB::table('plan_indicators')->where('source_type', 'proposal')->where('source_id', $proposal->id)->count();
}

test('one target per center; an empty cell writes no row and zero is a real target', function () {
    $result = saveGrid($this->proposal, [
        gridCell($this->indicator, $this->centerA, '120'),
        gridCell($this->indicator, $this->centerB, '0'),
    ]);

    expect($result)->toBe(['created' => 2, 'updated' => 0, 'deleted' => 0]);
    expect(DB::table('plan_indicators')->where('project_id', $this->centerB->id)->value('target'))->toBe('0.00');

    $empty = gridIndicator($this->output);
    saveGrid($this->proposal, [gridCell($empty, $this->centerA, null)]);
    expect(DB::table('plan_indicators')->where('indicator_id', $empty->id)->count())->toBe(0);
});

test('an existing cell updates with its version and clears when emptied', function () {
    saveGrid($this->proposal, [gridCell($this->indicator, $this->centerA, '120')]);
    $row = PlanIndicator::query()->sole();

    expect(saveGrid($this->proposal, [gridCell($this->indicator, $this->centerA, '150', ['version' => SaveProposalTargetsGrid::versionOf($row)])]))
        ->toBe(['created' => 0, 'updated' => 1, 'deleted' => 0]);

    $row->refresh();
    expect(saveGrid($this->proposal, [gridCell($this->indicator, $this->centerA, null, ['version' => SaveProposalTargetsGrid::versionOf($row)])]))
        ->toBe(['created' => 0, 'updated' => 0, 'deleted' => 1]);
    expect(proposalTargets($this->proposal))->toBe(0);
});

test('a cell for an unlinked project rejects the whole batch', function () {
    $outsider = Project::factory()->create(['program_id' => $this->program->id]);

    $errors = gridRefusal(fn () => saveGrid($this->proposal, [
        gridCell($this->indicator, $this->centerA, '120'),
        gridCell($this->indicator, $outsider, '50'),
    ]));

    expect($errors)->toHaveKey('cells.1', [__('targets.cell_outside_proposal')]);
    expect(proposalTargets($this->proposal))->toBe(0);
});

test('an indicator outside the proposal framework rejects the whole batch', function () {
    $programNode = Result::factory()->create(['type' => ResultType::Output, 'program_id' => $this->program->id]);
    $foreign = gridIndicator($programNode);

    $errors = gridRefusal(fn () => saveGrid($this->proposal, [
        gridCell($this->indicator, $this->centerA, '120'),
        gridCell($foreign, $this->centerA, '50'),
    ]));

    expect($errors)->toHaveKey('cells.1', [__('targets.cell_outside_proposal')]);
    expect(proposalTargets($this->proposal))->toBe(0);
});

test('an indicator not linked to the library takes no target', function () {
    $legacy = gridIndicator($this->output, linked: false);

    expect(gridRefusal(fn () => saveGrid($this->proposal, [gridCell($legacy, $this->centerA, '5')])))
        ->toHaveKey('cells.0', [__('targets.cell_indicator_not_linked')]);
});

test('values are validated', function () {
    $percentage = gridIndicator($this->output, IndicatorMeasure::Percentage);
    $average = gridIndicator($this->output, IndicatorMeasure::Average);

    $errors = gridRefusal(fn () => saveGrid($this->proposal, [
        gridCell($this->indicator, $this->centerA, '-1'),
        gridCell($this->indicator, $this->centerB, '5', ['period' => null]),
    ]));

    expect($errors)->toHaveKey('cells.0.value', [__('targets.value_negative')])
        ->toHaveKey('cells.1.period', [__('targets.period_required')]);

    expect(gridRefusal(fn () => saveGrid($this->proposal, [
        gridCell($this->indicator, $this->centerA, '5'),
        gridCell($percentage, $this->centerA, '101'),
    ])))->toHaveKey('cells.1', [__('targets.percentage_over_100')]);
    expect(proposalTargets($this->proposal))->toBe(0);

    expect(saveGrid($this->proposal, [gridCell($average, $this->centerA, '250')])['created'])->toBe(1);
});

test('a stale version rejects the whole batch and flags the cell', function () {
    saveGrid($this->proposal, [gridCell($this->indicator, $this->centerA, '120')]);

    $errors = gridRefusal(fn () => saveGrid($this->proposal, [
        gridCell($this->indicator, $this->centerB, '10'),
        gridCell($this->indicator, $this->centerA, '150', ['version' => '2000-01-01 00:00:00']),
    ]));

    expect($errors)->toHaveKey('cells.1', [__('targets.cell_stale')]);
    expect(DB::table('plan_indicators')->where('project_id', $this->centerA->id)->value('target'))->toBe('120.00')
        ->and(proposalTargets($this->proposal))->toBe(1);
});

test('a double submit writes no duplicates', function () {
    $cells = [gridCell($this->indicator, $this->centerA, '120')];
    saveGrid($this->proposal, $cells);

    gridRefusal(fn () => saveGrid($this->proposal, $cells));

    expect(proposalTargets($this->proposal))->toBe(1);
});

test('a target with reports cannot be cleared', function () {
    saveGrid($this->proposal, [gridCell($this->indicator, $this->centerA, '120')]);
    $row = PlanIndicator::query()->sole();
    insertReport($row->id, auth()->id());

    expect(gridRefusal(fn () => saveGrid($this->proposal, [gridCell($this->indicator, $this->centerA, null, ['version' => SaveProposalTargetsGrid::versionOf($row)])])))
        ->toHaveKey('cells.0', [__('targets.cell_has_reports')]);
    expect(proposalTargets($this->proposal))->toBe(1);
});

test('a concept note takes targets and a rejected proposal is read-only', function () {
    expect($this->proposal->status->value)->toBe('concept note');
    expect(saveGrid($this->proposal, [gridCell($this->indicator, $this->centerA, '1')])['created'])->toBe(1);

    DB::table('proposals')->where('id', $this->proposal->id)->update(['status' => 'rejected']);

    expect(gridRefusal(fn () => saveGrid($this->proposal->fresh(), [gridCell($this->indicator, $this->centerB, '1')])))
        ->toHaveKey('proposal', [__('targets.proposal_read_only')]);
});

test('target population defaults from the center beneficiaries for percentages', function () {
    $percentage = gridIndicator($this->output, IndicatorMeasure::Percentage);

    saveGrid($this->proposal, [
        gridCell($percentage, $this->centerA, '80'),
        gridCell($percentage, $this->centerB, '50', ['target_population' => '40']),
        gridCell($this->indicator, $this->centerA, '7'),
    ]);

    expect(DB::table('plan_indicators')->where('indicator_id', $percentage->id)->where('project_id', $this->centerA->id)->value('target_population'))->toBe('180.00')
        ->and(DB::table('plan_indicators')->where('indicator_id', $percentage->id)->where('project_id', $this->centerB->id)->value('target_population'))->toBe('40.00')
        ->and(DB::table('plan_indicators')->where('indicator_id', $this->indicator->id)->value('target_population'))->toBeNull();
});

test('the center total combines donors by the program attribution', function () {
    expect(DonorAttribution::Split->combine([60.0, 40.0]))->toBe(100.0)
        ->and(DonorAttribution::Shared->combine([60.0, 40.0]))->toBe(60.0)
        ->and(Program::factory()->create()->donor_attribution)->toBe(DonorAttribution::Shared);
});

test('one summary activity is recorded per save', function () {
    saveGrid($this->proposal, [
        gridCell($this->indicator, $this->centerA, '1'),
        gridCell($this->indicator, $this->centerB, '2'),
    ]);

    expect(DB::table('activities')->where('event', 'proposal.targets_saved')->count())->toBe(1)
        ->and(DB::table('activities')->where('subject_type', 'plan_indicator')->count())->toBe(0);
});

test('only MEAL may save the grid', function (string $role) {
    $this->actingAs(User::factory()->role($role)->create());

    saveGrid($this->proposal, [gridCell($this->indicator, $this->centerA, '1')]);
})->with(['General Manager', 'Managers', 'Financial'])->throws(AuthorizationException::class);

test('the window scope reads a project\'s proposal targets in a period', function () {
    DB::table('proposals')->where('id', $this->proposal->id)->update(['planned_date_begin' => '2026-01-01', 'planned_date_end' => '2026-12-31']);
    saveGrid($this->proposal, [gridCell($this->indicator, $this->centerA, '1'), gridCell($this->indicator, $this->centerB, '1')]);

    $inside = PlanIndicator::query()->forProjectInWindow($this->centerA->id, CarbonImmutable::parse('2026-06-01'), CarbonImmutable::parse('2026-06-30'))->count();
    $outside = PlanIndicator::query()->forProjectInWindow($this->centerA->id, CarbonImmutable::parse('2027-02-01'), CarbonImmutable::parse('2027-02-28'))->count();

    expect($inside)->toBe(1)->and($outside)->toBe(0);
});
