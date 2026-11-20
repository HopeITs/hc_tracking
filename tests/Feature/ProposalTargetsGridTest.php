<?php

use App\Actions\Targets\SaveProposalTargetsGrid;
use App\Enums\DonorAttribution;
use App\Enums\IndicatorMeasure;
use App\Enums\ResultType;
use App\Models\Indicator;
use App\Models\LibraryIndicator;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\ProgramRequest;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\Result;
use App\Models\User;
use App\Support\Reporting\ReportPlace;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(mealManager());

    $this->program = Program::factory()->create();
    $this->proposal = Proposal::factory()->create();
    $this->centerA = Project::factory()->create(['program_id' => $this->program->id]);
    $this->centerB = Project::factory()->create(['program_id' => $this->program->id]);
    DB::table('project_proposal')->insert([
        ['project_id' => $this->centerA->id, 'proposal_id' => $this->proposal->id, 'target_beneficiaries' => 180],
        ['project_id' => $this->centerB->id, 'proposal_id' => $this->proposal->id, 'target_beneficiaries' => null],
    ]);

    $this->rowA = proposalRowForProject($this->proposal, $this->centerA);
    $this->rowB = proposalRowForProject($this->proposal, $this->centerB);

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
function gridCell(Indicator $indicator, ProposalLocation $row, mixed $value, array $overrides = []): array
{
    return ['indicator_id' => $indicator->id, 'location_id' => $row->id, 'value' => $value, 'period' => $value === null ? null : 'annual', 'version' => null, ...$overrides];
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
        gridCell($this->indicator, $this->rowA, '120'),
        gridCell($this->indicator, $this->rowB, '0'),
    ]);

    expect($result)->toBe(['created' => 2, 'updated' => 0, 'deleted' => 0]);
    expect(DB::table('plan_indicators')->where('project_id', $this->centerB->id)->value('target'))->toBe('0.00');

    $empty = gridIndicator($this->output);
    saveGrid($this->proposal, [gridCell($empty, $this->rowA, null)]);
    expect(DB::table('plan_indicators')->where('indicator_id', $empty->id)->count())->toBe(0);
});

test('an existing cell updates with its version and clears when emptied', function () {
    saveGrid($this->proposal, [gridCell($this->indicator, $this->rowA, '120')]);
    $row = PlanIndicator::query()->sole();

    expect(saveGrid($this->proposal, [gridCell($this->indicator, $this->rowA, '150', ['version' => SaveProposalTargetsGrid::versionOf($row)])]))
        ->toBe(['created' => 0, 'updated' => 1, 'deleted' => 0]);

    $row->refresh();
    expect(saveGrid($this->proposal, [gridCell($this->indicator, $this->rowA, null, ['version' => SaveProposalTargetsGrid::versionOf($row)])]))
        ->toBe(['created' => 0, 'updated' => 0, 'deleted' => 1]);
    expect(proposalTargets($this->proposal))->toBe(0);
});

test('a row of another proposal rejects the whole batch', function () {
    $outsider = proposalRowForProject(Proposal::factory()->create(), Project::factory()->create(['program_id' => $this->program->id]));

    $errors = gridRefusal(fn () => saveGrid($this->proposal, [
        gridCell($this->indicator, $this->rowA, '120'),
        gridCell($this->indicator, $outsider, '50'),
    ]));

    expect($errors)->toHaveKey('cells.1', [__('targets.cell_outside_proposal')]);
    expect(proposalTargets($this->proposal))->toBe(0);
});

test('a cell that names a project is refused (Y-10)', function () {
    $errors = gridRefusal(fn () => saveGrid($this->proposal, [
        gridCell($this->indicator, $this->rowA, '120'),
        ['indicator_id' => $this->indicator->id, 'project_id' => $this->centerB->id, 'value' => '5', 'period' => 'annual', 'version' => null],
    ]));

    expect($errors)->toHaveKey('cells.1', [__('targets.cell_project_not_accepted')]);
    expect(proposalTargets($this->proposal))->toBe(0);
});

test('a cell on a two-center row saves one shared target that covers both centers (D-90)', function () {
    $second = insertHrCenter((int) $this->centerA->city_id, 'Second '.Str::random(5));
    $this->rowA->centers()->create(['center_id' => $second]);
    $first = $this->rowA->centerIds()[0] === $second ? $this->rowA->fresh()->centerIds()[1] : $this->rowA->fresh()->centerIds()[0];

    expect(saveGrid($this->proposal, [gridCell($this->indicator, $this->rowA, '300')])['created'])->toBe(1);

    $target = PlanIndicator::query()->sole();

    expect($target->only(['proposal_location_id', 'project_id', 'center_id', 'city_wide', 'all_centers']))->toBe([
        'proposal_location_id' => $this->rowA->id,
        'project_id' => $this->centerA->id,
        'center_id' => null,
        'city_wide' => false,
        'all_centers' => true,
    ])
        ->and($target->coversPlace(new ReportPlace((int) $this->centerA->city_id, $this->program->id, $first, false)))->toBeTrue()
        ->and($target->coversPlace(new ReportPlace((int) $this->centerA->city_id, $this->program->id, $second, false)))->toBeTrue();
});

test('a cell on a field-work row saves a city-wide target', function () {
    $project = Project::factory()->create(['program_id' => $this->program->id]);
    DB::table('project_proposal')->insert(['project_id' => $project->id, 'proposal_id' => $this->proposal->id]);
    $field = proposalRowForProject($this->proposal, $project, false);

    saveGrid($this->proposal, [gridCell($this->indicator, $field, '40')]);

    expect(PlanIndicator::query()->sole()->only(['proposal_location_id', 'center_id', 'city_wide', 'all_centers']))->toBe([
        'proposal_location_id' => $field->id,
        'center_id' => null,
        'city_wide' => true,
        'all_centers' => false,
    ]);
});

test('a row with no project yet takes a target with no project_id', function () {
    $row = ProposalLocation::factory()->create(['proposal_id' => $this->proposal->id, 'program_id' => $this->program->id, 'project_id' => null]);

    saveGrid($this->proposal, [gridCell($this->indicator, $row, '12')]);

    expect(PlanIndicator::query()->sole()->only(['proposal_location_id', 'project_id']))->toBe(['proposal_location_id' => $row->id, 'project_id' => null]);
});

test('a row waiting for its program refuses a new value but lets a saved one be cleared (Y-10)', function () {
    $waiting = ProposalLocation::factory()->isNew()->create(['proposal_id' => $this->proposal->id, 'program_id' => null]);
    ProgramRequest::factory()->create(['proposal_location_id' => $waiting->id]);

    expect(gridRefusal(fn () => saveGrid($this->proposal, [gridCell($this->indicator, $waiting, '10')])))
        ->toHaveKey('cells.0', [__('targets.cell_row_waiting_program')]);

    $saved = PlanIndicator::factory()->forProposal($this->proposal)->create(['indicator_id' => $this->indicator->id, 'proposal_location_id' => $waiting->id]);

    expect(saveGrid($this->proposal, [gridCell($this->indicator, $waiting, null, ['version' => SaveProposalTargetsGrid::versionOf($saved)])]))
        ->toBe(['created' => 0, 'updated' => 0, 'deleted' => 1]);
});

test('a second target for the same indicator and row is impossible', function () {
    saveGrid($this->proposal, [gridCell($this->indicator, $this->rowA, '120')]);

    expect(fn () => DB::table('plan_indicators')->insert([
        'source_type' => 'proposal',
        'source_id' => $this->proposal->id,
        'indicator_id' => $this->indicator->id,
        'proposal_location_id' => $this->rowA->id,
        'target' => 1,
    ]))->toThrow(QueryException::class, 'plan_indicators_indicator_location_unique');
});

test('an indicator outside the proposal framework rejects the whole batch', function () {
    $programNode = Result::factory()->create(['type' => ResultType::Output, 'program_id' => $this->program->id]);
    $foreign = gridIndicator($programNode);

    $errors = gridRefusal(fn () => saveGrid($this->proposal, [
        gridCell($this->indicator, $this->rowA, '120'),
        gridCell($foreign, $this->rowA, '50'),
    ]));

    expect($errors)->toHaveKey('cells.1', [__('targets.cell_outside_proposal')]);
    expect(proposalTargets($this->proposal))->toBe(0);
});

test('an indicator not linked to the library takes no target', function () {
    $legacy = gridIndicator($this->output, linked: false);

    expect(gridRefusal(fn () => saveGrid($this->proposal, [gridCell($legacy, $this->rowA, '5')])))
        ->toHaveKey('cells.0', [__('targets.cell_indicator_not_linked')]);
});

test('values are validated', function () {
    $percentage = gridIndicator($this->output, IndicatorMeasure::Percentage);
    $average = gridIndicator($this->output, IndicatorMeasure::Average);

    $errors = gridRefusal(fn () => saveGrid($this->proposal, [
        gridCell($this->indicator, $this->rowA, '-1'),
        gridCell($this->indicator, $this->rowB, '5', ['period' => null]),
    ]));

    expect($errors)->toHaveKey('cells.0.value', [__('targets.value_negative')])
        ->toHaveKey('cells.1.period', [__('targets.period_required')]);

    expect(gridRefusal(fn () => saveGrid($this->proposal, [
        gridCell($this->indicator, $this->rowA, '5'),
        gridCell($percentage, $this->rowA, '101'),
    ])))->toHaveKey('cells.1', [__('targets.percentage_over_100')]);
    expect(proposalTargets($this->proposal))->toBe(0);

    expect(saveGrid($this->proposal, [gridCell($average, $this->rowA, '250')])['created'])->toBe(1);
});

test('a stale version rejects the whole batch and flags the cell', function () {
    saveGrid($this->proposal, [gridCell($this->indicator, $this->rowA, '120')]);

    $errors = gridRefusal(fn () => saveGrid($this->proposal, [
        gridCell($this->indicator, $this->rowB, '10'),
        gridCell($this->indicator, $this->rowA, '150', ['version' => '2000-01-01 00:00:00']),
    ]));

    expect($errors)->toHaveKey('cells.1', [__('targets.cell_stale')]);
    expect(DB::table('plan_indicators')->where('project_id', $this->centerA->id)->value('target'))->toBe('120.00')
        ->and(proposalTargets($this->proposal))->toBe(1);
});

test('a double submit writes no duplicates', function () {
    $cells = [gridCell($this->indicator, $this->rowA, '120')];
    saveGrid($this->proposal, $cells);

    gridRefusal(fn () => saveGrid($this->proposal, $cells));

    expect(proposalTargets($this->proposal))->toBe(1);
});

test('a target with reports cannot be cleared', function () {
    saveGrid($this->proposal, [gridCell($this->indicator, $this->rowA, '120')]);
    $row = PlanIndicator::query()->sole();
    insertReport($row->id, auth()->id());

    expect(gridRefusal(fn () => saveGrid($this->proposal, [gridCell($this->indicator, $this->rowA, null, ['version' => SaveProposalTargetsGrid::versionOf($row)])])))
        ->toHaveKey('cells.0', [__('targets.cell_has_reports')]);
    expect(proposalTargets($this->proposal))->toBe(1);
});

test('a concept note takes targets and a rejected proposal is read-only', function () {
    expect($this->proposal->status->value)->toBe('concept note');
    expect(saveGrid($this->proposal, [gridCell($this->indicator, $this->rowA, '1')])['created'])->toBe(1);

    DB::table('proposals')->where('id', $this->proposal->id)->update(['status' => 'rejected']);

    expect(gridRefusal(fn () => saveGrid($this->proposal->fresh(), [gridCell($this->indicator, $this->rowB, '1')])))
        ->toHaveKey('proposal', [__('targets.proposal_read_only')]);
});

test('target population defaults from the center beneficiaries for percentages', function () {
    $percentage = gridIndicator($this->output, IndicatorMeasure::Percentage);

    saveGrid($this->proposal, [
        gridCell($percentage, $this->rowA, '80'),
        gridCell($percentage, $this->rowB, '50', ['target_population' => '40']),
        gridCell($this->indicator, $this->rowA, '7'),
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
        gridCell($this->indicator, $this->rowA, '1'),
        gridCell($this->indicator, $this->rowB, '2'),
    ]);

    expect(DB::table('activities')->where('event', 'proposal.targets_saved')->count())->toBe(1)
        ->and(DB::table('activities')->where('subject_type', 'plan_indicator')->count())->toBe(0);
});

test('only MEAL may save the grid', function (string $role) {
    $this->actingAs(User::factory()->role($role)->create());

    saveGrid($this->proposal, [gridCell($this->indicator, $this->rowA, '1')]);
})->with(['General Manager', 'Managers', 'Financial'])->throws(AuthorizationException::class);

test('the window scope reads a project\'s proposal targets in a period', function () {
    DB::table('proposals')->where('id', $this->proposal->id)->update(['planned_date_begin' => '2026-01-01', 'planned_date_end' => '2026-12-31']);
    saveGrid($this->proposal, [gridCell($this->indicator, $this->rowA, '1'), gridCell($this->indicator, $this->rowB, '1')]);

    $inside = PlanIndicator::query()->forProjectInWindow($this->centerA->id, CarbonImmutable::parse('2026-06-01'), CarbonImmutable::parse('2026-06-30'))->count();
    $outside = PlanIndicator::query()->forProjectInWindow($this->centerA->id, CarbonImmutable::parse('2027-02-01'), CarbonImmutable::parse('2027-02-28'))->count();

    expect($inside)->toBe(1)->and($outside)->toBe(0);
});

test('a saved cell carries the place of its row, not of its project (Y-10)', function () {
    $this->centerA->forceFill(['center_id' => 9101])->save();
    $rowCenter = $this->rowA->centerIds()[0];

    saveGrid($this->proposal, [gridCell($this->indicator, $this->rowA, '120')]);

    expect((array) DB::table('plan_indicators')->first(['city_id', 'program_id', 'center_id', 'city_wide', 'all_centers']))->toBe([
        'city_id' => $this->centerA->city_id,
        'program_id' => $this->program->id,
        'center_id' => $rowCenter,
        'city_wide' => 0,
        'all_centers' => 0,
    ]);
});

test('the grid reads a fixed number of queries per save (6 rows × 40 indicators)', function () {
    $count = function (array $rows, int $indicatorCount): int {
        $indicators = collect(range(1, $indicatorCount))->map(fn (): Indicator => gridIndicator($this->output))->all();
        $cells = [];

        foreach ($indicators as $indicator) {
            foreach ($rows as $row) {
                $cells[] = gridCell($indicator, $row, '10');
            }
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        saveGrid($this->proposal, $cells);
        $reads = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => str_starts_with(strtolower($sql), 'select'))->count();
        DB::disableQueryLog();

        return $reads;
    };

    $rows = [$this->rowA, $this->rowB];

    foreach (range(1, 4) as $i) {
        $project = Project::factory()->create(['program_id' => $this->program->id]);
        $rows[] = proposalRowForProject($this->proposal, $project);
    }

    $count([$this->rowB], 1); // warms the permission cache
    $small = $count([$this->rowA], 1);
    $large = $count($rows, 40);

    expect($large)->toBe($small)
        ->and(DB::table('plan_indicators')->whereNotNull('proposal_location_id')->count())->toBe(242);
});
