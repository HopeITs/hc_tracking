<?php

declare(strict_types=1);

use App\Enums\ResultType;
use App\Models\ActivitySchedule;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\Result;
use App\Support\Locations\ScheduleOverrideRowBackfill;
use Illuminate\Support\Facades\DB;

/**
 * Y-15 slice 1 (D-93, D-49): a centre's calendar override moves from its
 * project to the location row of the activity's own proposal that carries
 * that project. An activity is a result of one proposal and a row is of one
 * proposal, so no date rule is needed.
 */

/**
 * An activity of the proposal's framework.
 */
function overrideActivity(Proposal $proposal): Result
{
    $output = Result::factory()->type(ResultType::Output)->create(['proposal_id' => $proposal->id]);

    return Result::factory()->activity($output)->create(['proposal_id' => $proposal->id]);
}

/**
 * One stored month of a calendar, keyed by project only (the legacy shape).
 */
function legacyOverride(Result $activity, ?int $projectId, string $month): int
{
    return ActivitySchedule::factory()->create([
        'result_id' => $activity->id,
        'project_id' => $projectId,
        'month' => $month,
    ])->id;
}

function backfillOverrides(bool $apply = true): array
{
    return app(ScheduleOverrideRowBackfill::class)->run($apply);
}

function storedOverrideRow(int $scheduleId): ?int
{
    $value = DB::table('activity_schedules')->where('id', $scheduleId)->value('proposal_location_id');

    return $value === null ? null : (int) $value;
}

test('an override goes to the row of its own proposal even when another proposal carries the same project', function () {
    $project = Project::factory()->create();
    $older = Proposal::factory()->create();
    $newer = Proposal::factory()->create();
    $olderRow = proposalRowForProject($older, $project, false);
    $newerRow = proposalRowForProject($newer, $project, false);
    $activity = overrideActivity($older);

    $override = legacyOverride($activity, $project->id, '2026-03-01');
    $proposalCalendar = legacyOverride($activity, null, '2026-04-01');

    $counts = backfillOverrides();

    expect(storedOverrideRow($override))->toBe($olderRow->id)
        ->and(storedOverrideRow($override))->not->toBe($newerRow->id)
        ->and((int) DB::table('activity_schedules')->where('id', $override)->value('project_id'))->toBe($project->id)
        ->and(storedOverrideRow($proposalCalendar))->toBeNull()
        ->and($counts)->toBe([
            'overrides_seen' => 1,
            'moved' => 1,
            'by_own_proposal' => 1,
            'by_date' => 0,
            'by_most_recent' => 0,
            'rows' => [$olderRow->id => 1],
            'unmapped' => [],
        ]);
});

test('an override whose project no row of its own proposal carries is listed and left alone', function () {
    $project = Project::factory()->create();
    $own = Proposal::factory()->create();
    proposalRowForProject(Proposal::factory()->create(), $project, false);
    $activity = overrideActivity($own);

    $override = legacyOverride($activity, $project->id, '2026-03-01');

    $counts = backfillOverrides();

    expect(storedOverrideRow($override))->toBeNull()
        ->and($counts['moved'])->toBe(0)
        ->and($counts['unmapped'])->toBe([$override]);
});

test('the dry run reports the same counts and writes nothing', function () {
    $project = Project::factory()->create();
    $proposal = Proposal::factory()->create();
    $row = proposalRowForProject($proposal, $project, false);
    $activity = overrideActivity($proposal);
    $march = legacyOverride($activity, $project->id, '2026-03-01');
    $april = legacyOverride($activity, $project->id, '2026-04-01');

    $counts = backfillOverrides(apply: false);

    expect($counts['moved'])->toBe(2)
        ->and($counts['rows'])->toBe([$row->id => 2])
        ->and(storedOverrideRow($march))->toBeNull()
        ->and(storedOverrideRow($april))->toBeNull();
});

test('the command prints its counts, writes only when applied, and is safe to run twice', function () {
    $project = Project::factory()->create();
    $proposal = Proposal::factory()->create();
    $row = proposalRowForProject($proposal, $project, false);
    $override = legacyOverride(overrideActivity($proposal), $project->id, '2026-03-01');

    $this->artisan('activity-schedules:row-backfill', ['--dry-run' => true])
        ->expectsOutputToContain(__('results_framework.console.backfill_dry_run'))
        ->expectsOutputToContain(__('results_framework.console.backfill_unmapped', ['ids' => __('results_framework.console.backfill_none')]))
        ->assertSuccessful();

    expect(storedOverrideRow($override))->toBeNull();

    $this->artisan('activity-schedules:row-backfill')->assertSuccessful();
    $this->artisan('activity-schedules:row-backfill')->assertSuccessful();

    expect(storedOverrideRow($override))->toBe($row->id)
        ->and(backfillOverrides()['overrides_seen'])->toBe(0);
});

test('the tightening migration refuses while an override has no row', function () {
    $project = Project::factory()->create();
    $override = legacyOverride(overrideActivity(Proposal::factory()->create()), $project->id, '2026-03-01');

    $migration = require database_path('migrations/2026_11_23_140002_key_activity_schedules_on_location_row.php');

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, (string) $override);
});

test('a removed row takes its override with it', function () {
    $project = Project::factory()->create();
    $proposal = Proposal::factory()->create();
    $row = proposalRowForProject($proposal, $project, false);
    $override = legacyOverride(overrideActivity($proposal), $project->id, '2026-03-01');
    backfillOverrides();

    ProposalLocation::query()->whereKey($row->id)->delete();

    expect(DB::table('activity_schedules')->where('id', $override)->exists())->toBeFalse();
});
