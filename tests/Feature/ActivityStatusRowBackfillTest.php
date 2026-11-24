<?php

declare(strict_types=1);

use App\Enums\ActivityStatus;
use App\Enums\ResultType;
use App\Models\Activity;
use App\Models\MonthlyReport;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\Result;
use App\Support\Locations\ActivityStatusRowBackfill;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Y-15 slice 2 (D-93, D-51): an activity status confirmed by a monthly report
 * moves from its project to the location row of the activity's own proposal
 * that carries that project. No date rule is needed.
 *
 * Legacy rows (no location) cannot exist once the tightening migration has
 * run, so each test rolls that migration back first. DDL commits the
 * RefreshDatabase transaction in MySQL, so afterEach deletes every row the
 * test committed (by id above a snapshot) and re-applies the migration, as
 * MonthlyReportTemplateBackfillTest does for Y-03. After each DDL a real
 * transaction is started again, so the one Laravel still counts as open (and
 * the backfill's savepoint inside it) exists on the server.
 */
beforeEach(function () {
    $this->tightening = require database_path('migrations/2026_11_23_160002_key_project_activity_statuses_on_location_row.php');
    $this->idSnapshot = statusTestIdSnapshot();
    $this->tightening->down();
    DB::unprepared('START TRANSACTION');
});

afterEach(function () {
    DB::statement('SET FOREIGN_KEY_CHECKS=0');

    foreach ($this->idSnapshot as $table => $maxId) {
        DB::table($table)->where('id', '>', $maxId)->delete();
    }

    DB::statement('SET FOREIGN_KEY_CHECKS=1');

    $this->tightening->up();
    DB::unprepared('START TRANSACTION');
});

/**
 * The highest id of every tracking table that has one.
 *
 * @return array<string, int>
 */
function statusTestIdSnapshot(): array
{
    $snapshot = [];

    foreach (Schema::getTableListing(schemaQualified: false) as $table) {
        if (Schema::hasColumn($table, 'id')) {
            $snapshot[$table] = (int) DB::table($table)->max('id');
        }
    }

    return $snapshot;
}

function statusActivity(Proposal $proposal): Result
{
    $output = Result::factory()->type(ResultType::Output)->create(['proposal_id' => $proposal->id]);

    return Result::factory()->activity($output)->create(['proposal_id' => $proposal->id]);
}

/**
 * A confirmed status in the legacy shape: keyed by project only.
 */
function legacyStatus(Result $activity, Project $project): int
{
    $report = Activity::withoutRecording(fn (): MonthlyReport => MonthlyReport::factory()->validated()->create(['project_id' => $project->id]));

    return (int) DB::table('project_activity_statuses')->insertGetId([
        'project_id' => $project->id,
        'result_id' => $activity->id,
        'status' => ActivityStatus::Ongoing->value,
        'monthly_report_id' => $report->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function backfillStatuses(bool $apply = true): array
{
    return app(ActivityStatusRowBackfill::class)->run($apply);
}

function storedStatusRow(int $statusId): ?int
{
    $value = DB::table('project_activity_statuses')->where('id', $statusId)->value('proposal_location_id');

    return $value === null ? null : (int) $value;
}

test('a status goes to the row of its own proposal even when another proposal carries the same project', function () {
    $project = Project::factory()->create();
    $own = Proposal::factory()->create();
    $ownRow = proposalRowForProject($own, $project, false);
    proposalRowForProject(Proposal::factory()->create(), $project, false);
    $status = legacyStatus(statusActivity($own), $project);

    $counts = backfillStatuses();

    expect(storedStatusRow($status))->toBe($ownRow->id)
        ->and((int) DB::table('project_activity_statuses')->where('id', $status)->value('project_id'))->toBe($project->id)
        ->and($counts)->toBe([
            'statuses_seen' => 1,
            'moved' => 1,
            'by_own_proposal' => 1,
            'by_date' => 0,
            'by_most_recent' => 0,
            'rows' => [$ownRow->id => 1],
            'unmapped' => [],
        ]);
});

test('a status whose project no row of its own proposal carries is listed, left alone, and blocks the tightening', function () {
    $project = Project::factory()->create();
    proposalRowForProject(Proposal::factory()->create(), $project, false);
    $status = legacyStatus(statusActivity(Proposal::factory()->create()), $project);

    $counts = backfillStatuses();

    expect(storedStatusRow($status))->toBeNull()
        ->and($counts['unmapped'])->toBe([$status]);

    expect(fn () => $this->tightening->up())->toThrow(RuntimeException::class, (string) $status);

    DB::table('project_activity_statuses')->where('id', $status)->delete();
});

test('the dry run reports the same counts and writes nothing', function () {
    $project = Project::factory()->create();
    $proposal = Proposal::factory()->create();
    $row = proposalRowForProject($proposal, $project, false);
    $status = legacyStatus(statusActivity($proposal), $project);

    $counts = backfillStatuses(apply: false);

    expect($counts['moved'])->toBe(1)
        ->and($counts['rows'])->toBe([$row->id => 1])
        ->and(storedStatusRow($status))->toBeNull();
});

test('the command prints its counts, writes only when applied, and is safe to run twice', function () {
    $project = Project::factory()->create();
    $proposal = Proposal::factory()->create();
    $row = proposalRowForProject($proposal, $project, false);
    $status = legacyStatus(statusActivity($proposal), $project);

    $this->artisan('activity-statuses:row-backfill', ['--dry-run' => true])
        ->expectsOutputToContain(__('monthly_reports.console.status_backfill_dry_run'))
        ->expectsOutputToContain(__('monthly_reports.console.status_backfill_unmapped', ['ids' => __('monthly_reports.console.status_backfill_none')]))
        ->assertSuccessful();

    expect(storedStatusRow($status))->toBeNull();

    $this->artisan('activity-statuses:row-backfill')->assertSuccessful();
    $this->artisan('activity-statuses:row-backfill')->assertSuccessful();

    expect(storedStatusRow($status))->toBe($row->id)
        ->and(backfillStatuses()['statuses_seen'])->toBe(0);
});

test('a removed row takes its statuses with it', function () {
    $project = Project::factory()->create();
    $proposal = Proposal::factory()->create();
    $row = proposalRowForProject($proposal, $project, false);
    $status = legacyStatus(statusActivity($proposal), $project);
    backfillStatuses();

    ProposalLocation::query()->whereKey($row->id)->delete();

    expect(DB::table('project_activity_statuses')->where('id', $status)->exists())->toBeFalse();
});
