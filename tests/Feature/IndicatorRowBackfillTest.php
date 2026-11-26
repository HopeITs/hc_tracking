<?php

declare(strict_types=1);

use App\Models\Indicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Result;
use App\Support\Locations\IndicatorRowBackfill;
use Illuminate\Support\Facades\DB;

/**
 * Y-16 slice 1 (D-93, D-94): an indicator instance scoped to a project moves
 * onto the location row that took the project's place.
 *
 * An instance under a proposal's framework belongs to a row of *that*
 * proposal, so the row is exact and no date rule is needed. One under the
 * program framework has no proposal of its own, so it goes through
 * ProjectSuccessor by its `created_at` (D-94).
 */

/**
 * An indicator scoped to $project, under the framework of $proposal when one
 * is given and under the program framework otherwise.
 */
function backfillIndicator(Project $project, ?Proposal $proposal = null, ?string $createdAt = null): Indicator
{
    $result = Result::factory()->create([
        'proposal_id' => $proposal?->id,
        'program_id' => $project->program_id,
    ]);

    $indicator = Indicator::factory()->forProject($project->id)->create(['result_id' => $result->id]);

    if ($createdAt !== null) {
        DB::table('indicators')->where('id', $indicator->id)->update(['created_at' => $createdAt]);
    }

    return $indicator;
}

test('an instance under a proposal framework takes the row of its own proposal', function () {
    $project = Project::factory()->create();
    $proposal = Proposal::factory()->create(['actual_date_begin' => '2026-01-01', 'actual_date_end' => '2026-12-31']);
    $row = proposalRowForProject($proposal, $project);

    // A second, more recent proposal also carries the project: the date rule
    // would pick its row, but the instance's own proposal must win.
    $later = Proposal::factory()->create(['actual_date_begin' => '2027-01-01', 'actual_date_end' => '2027-12-31']);
    proposalRowForProject($later, $project);

    $indicator = backfillIndicator($project, $proposal);

    $counts = app(IndicatorRowBackfill::class)->run(apply: true);

    expect(DB::table('indicators')->where('id', $indicator->id)->value('proposal_location_id'))->toBe($row->id)
        ->and($counts['seen'])->toBe(1)
        ->and($counts['moved'])->toBe(1)
        ->and($counts['by_own_proposal'])->toBe(1)
        ->and($counts['by_date'])->toBe(0)
        ->and($counts['by_most_recent'])->toBe(0)
        ->and($counts['unmapped'])->toBe([]);
});

test('an instance under the program framework takes the row whose proposal period covers its created_at', function () {
    $project = Project::factory()->create();
    $first = Proposal::factory()->create(['actual_date_begin' => '2025-01-01', 'actual_date_end' => '2025-12-31']);
    $firstRow = proposalRowForProject($first, $project);
    $second = Proposal::factory()->create(['actual_date_begin' => '2026-01-01', 'actual_date_end' => '2026-12-31']);
    proposalRowForProject($second, $project);

    $indicator = backfillIndicator($project, null, '2025-06-15 09:00:00');

    $counts = app(IndicatorRowBackfill::class)->run(apply: true);

    expect(DB::table('indicators')->where('id', $indicator->id)->value('proposal_location_id'))->toBe($firstRow->id)
        ->and($counts['by_own_proposal'])->toBe(0)
        ->and($counts['by_date'])->toBe(1)
        ->and($counts['by_most_recent'])->toBe(0);
});

test('an instance under the program framework whose created_at no proposal covers takes the most recent row', function () {
    $project = Project::factory()->create();
    proposalRowForProject(Proposal::factory()->create(['actual_date_begin' => '2025-01-01', 'actual_date_end' => '2025-12-31']), $project);
    $second = Proposal::factory()->create(['actual_date_begin' => '2026-01-01', 'actual_date_end' => '2026-12-31']);
    $secondRow = proposalRowForProject($second, $project);

    $indicator = backfillIndicator($project, null, '2024-03-01 09:00:00');

    $counts = app(IndicatorRowBackfill::class)->run(apply: true);

    expect(DB::table('indicators')->where('id', $indicator->id)->value('proposal_location_id'))->toBe($secondRow->id)
        ->and($counts['by_date'])->toBe(0)
        ->and($counts['by_most_recent'])->toBe(1);
});

test('an instance whose project no row carries is listed and left alone', function () {
    $project = Project::factory()->create();
    $indicator = backfillIndicator($project);

    $counts = app(IndicatorRowBackfill::class)->run(apply: true);

    expect(DB::table('indicators')->where('id', $indicator->id)->value('proposal_location_id'))->toBeNull()
        ->and($counts['seen'])->toBe(1)
        ->and($counts['moved'])->toBe(0)
        ->and($counts['unmapped'])->toBe([$indicator->id]);
});

test('an instance with no project is never seen', function () {
    $project = Project::factory()->create();
    proposalRowForProject(Proposal::factory()->create(), $project);
    $indicator = Indicator::factory()->create();

    $counts = app(IndicatorRowBackfill::class)->run(apply: true);

    expect(DB::table('indicators')->where('id', $indicator->id)->value('proposal_location_id'))->toBeNull()
        ->and($counts['seen'])->toBe(0);
});

test('a dry run reports the same counts and writes nothing', function () {
    $project = Project::factory()->create();
    $proposal = Proposal::factory()->create(['actual_date_begin' => '2026-01-01', 'actual_date_end' => '2026-12-31']);
    proposalRowForProject($proposal, $project);
    $indicator = backfillIndicator($project, $proposal);

    $counts = app(IndicatorRowBackfill::class)->run(apply: false);

    expect($counts['seen'])->toBe(1)
        ->and($counts['moved'])->toBe(1)
        ->and(DB::table('indicators')->where('id', $indicator->id)->value('proposal_location_id'))->toBeNull();
});

test('a second run moves nothing and leaves project_id alone', function () {
    $project = Project::factory()->create();
    $proposal = Proposal::factory()->create(['actual_date_begin' => '2026-01-01', 'actual_date_end' => '2026-12-31']);
    $row = proposalRowForProject($proposal, $project);
    $indicator = backfillIndicator($project, $proposal);

    app(IndicatorRowBackfill::class)->run(apply: true);
    $counts = app(IndicatorRowBackfill::class)->run(apply: true);

    $stored = DB::table('indicators')->where('id', $indicator->id)->first(['project_id', 'proposal_location_id']);

    expect($counts['seen'])->toBe(0)
        ->and($counts['moved'])->toBe(0)
        ->and((int) $stored->proposal_location_id)->toBe($row->id)
        ->and((int) $stored->project_id)->toBe($project->id);
});

test('the backfill records no activity', function () {
    $project = Project::factory()->create();
    $proposal = Proposal::factory()->create(['actual_date_begin' => '2026-01-01', 'actual_date_end' => '2026-12-31']);
    proposalRowForProject($proposal, $project);
    backfillIndicator($project, $proposal);

    $before = DB::table('activities')->count();

    app(IndicatorRowBackfill::class)->run(apply: true);

    expect(DB::table('activities')->count())->toBe($before);
});

test('deleting a location row clears the instances that pointed at it', function () {
    $project = Project::factory()->create();
    $proposal = Proposal::factory()->create(['actual_date_begin' => '2026-01-01', 'actual_date_end' => '2026-12-31']);
    $row = proposalRowForProject($proposal, $project);
    $indicator = backfillIndicator($project, $proposal);

    app(IndicatorRowBackfill::class)->run(apply: true);

    DB::table('proposal_locations')->where('id', $row->id)->delete();

    $stored = DB::table('indicators')->where('id', $indicator->id)->first(['project_id', 'proposal_location_id']);

    expect($stored->proposal_location_id)->toBeNull()
        ->and((int) $stored->project_id)->toBe($project->id);
});

test('the row of a project is read once however many instances carry it', function () {
    $project = Project::factory()->create();
    $proposal = Proposal::factory()->create(['actual_date_begin' => '2026-01-01', 'actual_date_end' => '2026-12-31']);
    proposalRowForProject($proposal, $project);

    foreach (range(1, 5) as $ignored) {
        backfillIndicator($project, $proposal);
    }

    DB::enableQueryLog();
    app(IndicatorRowBackfill::class)->run(apply: true);
    $locationReads = collect(DB::getRawQueryLog())
        ->filter(fn (array $entry): bool => str_contains($entry['raw_query'], 'from `proposal_locations`'))
        ->count();
    DB::disableQueryLog();

    expect($locationReads)->toBe(1);
});
