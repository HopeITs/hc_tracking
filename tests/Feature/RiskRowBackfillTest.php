<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\Result;
use App\Models\Risk;
use App\Support\Locations\RiskRowBackfill;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Y-13 (D-93, D-94): every register risk is moved onto the location row that
 * took its project's place. `risks` carries no identification date — the four
 * risk migrations never added one — so D-94's "the day it was identified,
 * else the day it was recorded" is `created_at` for every risk.
 */

/**
 * A proposal carrying the project through one location row, dated by its
 * actual period.
 */
function riskRow(Project $project, string $begin, string $end): ProposalLocation
{
    $proposal = Proposal::factory()->create([
        'actual_date_begin' => $begin,
        'actual_date_end' => $end,
    ]);

    return proposalRowForProject($proposal, $project, false);
}

/**
 * A register risk recorded on $recordedOn, with no proposal of its own.
 */
function registerRisk(Project $project, string $recordedOn, array $overrides = []): Risk
{
    $risk = Risk::factory()->create(['project_id' => $project->id, ...$overrides]);

    DB::table('risks')->where('id', $risk->id)->update(['created_at' => $recordedOn]);

    return $risk->refresh();
}

function backfillRisks(bool $apply = true): array
{
    return app(RiskRowBackfill::class)->run($apply);
}

function storedRisk(int $riskId): object
{
    return DB::table('risks')->find($riskId);
}

test('a risk on a project carried by one row moves to that row and keeps its project', function () {
    $project = Project::factory()->create();
    $row = riskRow($project, '2026-01-01', '2026-12-31');
    $risk = registerRisk($project, '2026-06-01 09:00:00');

    $counts = backfillRisks();

    $stored = storedRisk($risk->id);

    expect((int) $stored->proposal_location_id)->toBe($row->id)
        ->and((int) $stored->project_id)->toBe($project->id)
        ->and((int) $stored->proposal_id)->toBe($row->proposal_id)
        ->and($counts['risks_moved'])->toBe(1)
        ->and($counts['by_date'])->toBe(1)
        ->and($counts['by_most_recent'])->toBe(0)
        ->and($counts['by_own_proposal'])->toBe(0)
        ->and($counts['unmapped'])->toBe([]);
});

test('a project carried by two proposals sends the risk to the row whose period covers the day it was recorded', function () {
    $project = Project::factory()->create();
    $first = riskRow($project, '2025-01-01', '2025-12-31');
    $second = riskRow($project, '2026-01-01', '2026-12-31');

    $early = registerRisk($project, '2025-03-01 09:00:00');
    $late = registerRisk($project, '2026-03-01 09:00:00');

    $counts = backfillRisks();

    expect((int) storedRisk($early->id)->proposal_location_id)->toBe($first->id)
        ->and((int) storedRisk($late->id)->proposal_location_id)->toBe($second->id)
        ->and($counts['by_date'])->toBe(2)
        ->and($counts['by_most_recent'])->toBe(0)
        ->and($counts['rows'])->toBe([$first->id => 1, $second->id => 1]);
});

test('a day no proposal covers falls to the row of the most recent proposal', function () {
    $project = Project::factory()->create();
    riskRow($project, '2025-01-01', '2025-12-31');
    $second = riskRow($project, '2026-01-01', '2026-12-31');

    $risk = registerRisk($project, '2024-05-01 09:00:00');

    $counts = backfillRisks();

    expect((int) storedRisk($risk->id)->proposal_location_id)->toBe($second->id)
        ->and($counts['by_date'])->toBe(0)
        ->and($counts['by_most_recent'])->toBe(1);
});

test('a risk that already names a proposal takes that proposal\'s row and ignores the date', function () {
    $project = Project::factory()->create();
    $first = riskRow($project, '2025-01-01', '2025-12-31');
    $second = riskRow($project, '2026-01-01', '2026-12-31');

    // Recorded inside the second proposal's period, but it belongs to the first.
    $risk = registerRisk($project, '2026-03-01 09:00:00', ['proposal_id' => $first->proposal_id]);

    $counts = backfillRisks();

    expect((int) storedRisk($risk->id)->proposal_location_id)->toBe($first->id)
        ->and((int) storedRisk($risk->id)->proposal_id)->toBe($first->proposal_id)
        ->and($counts['by_own_proposal'])->toBe(1)
        ->and($counts['by_date'])->toBe(0)
        ->and($counts['by_most_recent'])->toBe(0)
        ->and($second->id)->not->toBe($first->id);
});

test('a logframe risk on a proposal result is left alone', function () {
    $proposal = Proposal::factory()->create();
    $result = Result::factory()->create(['proposal_id' => $proposal->id]);
    $risk = Risk::factory()->create([
        'project_id' => null,
        'proposal_id' => $proposal->id,
        'result_id' => $result->id,
    ]);

    $counts = backfillRisks();

    expect(storedRisk($risk->id)->proposal_location_id)->toBeNull()
        ->and((int) storedRisk($risk->id)->proposal_id)->toBe($proposal->id)
        ->and($counts['risks_moved'])->toBe(0);
});

test('a risk whose project no row carries is listed and left alone', function () {
    $project = Project::factory()->create();
    $risk = registerRisk($project, '2026-06-01 09:00:00');

    $counts = backfillRisks();

    expect(storedRisk($risk->id)->proposal_location_id)->toBeNull()
        ->and((int) storedRisk($risk->id)->project_id)->toBe($project->id)
        ->and($counts['risks_moved'])->toBe(0)
        ->and($counts['unmapped'])->toBe([$risk->id]);
});

test('a dry run reports the same counts and writes nothing', function () {
    $project = Project::factory()->create();
    riskRow($project, '2026-01-01', '2026-12-31');
    $risk = registerRisk($project, '2026-06-01 09:00:00');

    $dry = backfillRisks(apply: false);

    expect(storedRisk($risk->id)->proposal_location_id)->toBeNull()
        ->and(storedRisk($risk->id)->proposal_id)->toBeNull()
        ->and($dry['risks_moved'])->toBe(1)
        ->and($dry['by_date'])->toBe(1);

    expect(backfillRisks())->toEqual($dry);
});

test('running twice moves nothing the second time', function () {
    $project = Project::factory()->create();
    riskRow($project, '2026-01-01', '2026-12-31');
    registerRisk($project, '2026-06-01 09:00:00');

    expect(backfillRisks()['risks_moved'])->toBe(1)
        ->and(backfillRisks()['risks_moved'])->toBe(0);
});

test('the command writes only with no --dry-run and prints the ids it could not map', function () {
    $carried = Project::factory()->create();
    riskRow($carried, '2026-01-01', '2026-12-31');
    $moving = registerRisk($carried, '2026-06-01 09:00:00');

    $orphan = registerRisk(Project::factory()->create(), '2026-06-01 09:00:00');

    $this->artisan('risks:row-backfill', ['--dry-run' => true])
        ->expectsOutputToContain((string) $orphan->id)
        ->assertSuccessful();

    expect(storedRisk($moving->id)->proposal_location_id)->toBeNull();

    $this->artisan('risks:row-backfill')->assertSuccessful();

    expect(storedRisk($moving->id)->proposal_location_id)->not->toBeNull();
});

test('the backfill records no activity', function () {
    $project = Project::factory()->create();
    riskRow($project, '2026-01-01', '2026-12-31');
    registerRisk($project, '2026-06-01 09:00:00');

    $before = DB::table('activities')->count();

    backfillRisks();

    expect(DB::table('activities')->count())->toBe($before);
});

test('a risk recorded with no date at all falls to the row of the most recent proposal', function () {
    $project = Project::factory()->create();
    riskRow($project, '2025-01-01', '2025-12-31');
    $second = riskRow($project, '2026-01-01', '2026-12-31');

    $risk = Risk::factory()->create(['project_id' => $project->id]);
    DB::table('risks')->where('id', $risk->id)->update(['created_at' => null]);

    $counts = backfillRisks();

    expect((int) storedRisk($risk->id)->proposal_location_id)->toBe($second->id)
        ->and($counts['by_most_recent'])->toBe(1);
});

test('the risk of a project whose single row sits outside the recorded day still moves to it', function () {
    $project = Project::factory()->create();
    $row = riskRow($project, '2026-01-01', '2026-12-31');
    $risk = registerRisk($project, '2020-01-01 09:00:00');

    $counts = backfillRisks();

    expect((int) storedRisk($risk->id)->proposal_location_id)->toBe($row->id)
        ->and($counts['by_most_recent'])->toBe(1)
        ->and(CarbonImmutable::parse(storedRisk($risk->id)->created_at)->toDateString())->toBe('2020-01-01');
});
