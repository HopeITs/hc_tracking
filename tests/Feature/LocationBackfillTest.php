<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\ProjectStatus;
use App\Models\Assignment;
use App\Models\Media;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\Sector;
use App\Support\Locations\LocationBackfill;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Y-11 slice 1: the backfill that makes every location row a faithful copy of
 * the project it carries (D-93, D-94).
 */

/**
 * A project with everything the row must end up carrying.
 */
function backfillProject(array $overrides = []): Project
{
    return Project::factory()->create([
        'status' => ProjectStatus::Active->value,
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'description' => 'What this project does',
        'schedule_weekend' => 'fri-sat',
        'working_days' => [1, 2, 3, 4, 5],
        'target_beneficiaries' => 500,
        'target_men' => 100,
        'target_women' => 150,
        'target_boys' => 120,
        'target_girls' => 130,
        ...$overrides,
    ]);
}

/**
 * A proposal carrying the project through one city-wide row.
 */
function backfillRow(Project $project, string $begin, string $end, array $overrides = []): ProposalLocation
{
    $proposal = Proposal::factory()->create([
        'actual_date_begin' => $begin,
        'actual_date_end' => $end,
        ...$overrides,
    ]);

    $proposal->projects()->attach($project->id);

    return proposalRowForProject($proposal, $project, false);
}

test('a project on one row copies every field it carries', function () {
    $project = backfillProject();
    $row = backfillRow($project, '2026-01-01', '2026-12-31');

    app(LocationBackfill::class)->run(apply: true);

    $stored = DB::table('proposal_locations')->find($row->id);

    expect($stored->status)->toBe(ProjectStatus::Active->value)
        ->and($stored->start_date)->toBe('2026-01-01')
        ->and($stored->end_date)->toBe('2026-12-31')
        ->and($stored->description)->toBe('What this project does')
        ->and($stored->schedule_weekend)->toBe('fri-sat')
        ->and(json_decode((string) $stored->working_days, true))->toBe([1, 2, 3, 4, 5])
        ->and((int) $stored->target_beneficiaries)->toBe(500)
        ->and((int) $stored->target_men)->toBe(100)
        ->and((int) $stored->target_women)->toBe(150)
        ->and((int) $stored->target_boys)->toBe(120)
        ->and((int) $stored->target_girls)->toBe(130)
        ->and($stored->slug)->not->toBeNull();
});

test('the row takes its own proposal beneficiaries over the project total', function () {
    $project = backfillProject();
    $row = backfillRow($project, '2026-01-01', '2026-12-31');
    $row->proposal->projects()->updateExistingPivot($project->id, ['target_beneficiaries' => 77]);

    app(LocationBackfill::class)->run(apply: true);

    expect((int) DB::table('proposal_locations')->find($row->id)->target_beneficiaries)->toBe(77);
});

test('the period is clipped to the row own proposal', function () {
    $project = backfillProject(['start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
    $row = backfillRow($project, '2026-03-01', '2026-08-31');

    app(LocationBackfill::class)->run(apply: true);

    $stored = DB::table('proposal_locations')->find($row->id);

    expect($stored->start_date)->toBe('2026-03-01')
        ->and($stored->end_date)->toBe('2026-08-31');
});

test('a project on two rows closes the earlier proposal row and copies sectors to both', function () {
    $project = backfillProject();
    $sectors = Sector::factory()->count(2)->create();
    $project->sectors()->attach($sectors->pluck('id'));

    $earlier = backfillRow($project, '2024-01-01', '2024-12-31');
    $later = backfillRow($project, '2026-01-01', '2026-12-31');

    app(LocationBackfill::class)->run(apply: true);

    expect(DB::table('proposal_locations')->find($earlier->id)->status)->toBe(ProjectStatus::Closed->value)
        ->and(DB::table('proposal_locations')->find($later->id)->status)->toBe(ProjectStatus::Active->value)
        ->and(DB::table('proposal_location_sector')->where('proposal_location_id', $earlier->id)->count())->toBe(2)
        ->and(DB::table('proposal_location_sector')->where('proposal_location_id', $later->id)->count())->toBe(2);
});

test('an earlier proposal whose period has not ended keeps the project status', function () {
    $project = backfillProject();
    $earlier = backfillRow($project, '2026-01-01', '2099-12-31');
    backfillRow($project, '2026-06-01', '2027-05-31');

    app(LocationBackfill::class)->run(apply: true);

    expect(DB::table('proposal_locations')->find($earlier->id)->status)->toBe(ProjectStatus::Active->value);
});

test('an archived project stays archived on every row', function () {
    $project = backfillProject(['status' => ProjectStatus::Archived->value]);
    $earlier = backfillRow($project, '2024-01-01', '2024-12-31');
    $later = backfillRow($project, '2026-01-01', '2026-12-31');

    app(LocationBackfill::class)->run(apply: true);

    expect(DB::table('proposal_locations')->find($earlier->id)->status)->toBe(ProjectStatus::Archived->value)
        ->and(DB::table('proposal_locations')->find($later->id)->status)->toBe(ProjectStatus::Archived->value);
});

test('a file is mirrored onto the one row its date picks, never onto every row', function () {
    $project = backfillProject();
    $earlier = backfillRow($project, '2024-01-01', '2024-12-31');
    $later = backfillRow($project, '2026-01-01', '2026-12-31');

    $old = Media::factory()->create(['mediable_type' => 'project', 'mediable_id' => $project->id, 'created_at' => '2024-06-01']);
    $new = Media::factory()->create(['mediable_type' => 'project', 'mediable_id' => $project->id, 'created_at' => '2026-06-01']);

    app(LocationBackfill::class)->run(apply: true);

    $mirrored = DB::table('media')->where('mediable_type', 'proposal_location')->get()->keyBy('mediable_id');

    expect($mirrored)->toHaveCount(2)
        ->and($mirrored[$earlier->id]->path)->toBe($old->path)
        ->and($mirrored[$later->id]->path)->toBe($new->path);
});

test('the project keeps its own files so its pages still show them before Y-16', function () {
    $project = backfillProject();
    backfillRow($project, '2026-01-01', '2026-12-31');
    $file = Media::factory()->create(['mediable_type' => 'project', 'mediable_id' => $project->id, 'created_at' => '2026-06-01']);

    app(LocationBackfill::class)->run(apply: true);

    expect(DB::table('media')->find($file->id)->mediable_type)->toBe('project')
        ->and((int) DB::table('media')->find($file->id)->mediable_id)->toBe($project->id);
});

test('the owner acknowledgement goes to the most recent row and earlier rows are written acknowledged', function () {
    $project = backfillProject();
    $earlier = backfillRow($project, '2024-01-01', '2024-12-31');
    $later = backfillRow($project, '2026-01-01', '2026-12-31');

    $acknowledgedAt = CarbonImmutable::parse('2026-02-03 10:00:00');
    Assignment::query()->where('assignable_type', 'project')->where('assignable_id', $project->id)->delete();
    Assignment::factory()->create([
        'assignable_type' => 'project',
        'assignable_id' => $project->id,
        'field' => 'user_id',
        'employee_id' => $project->user_id,
        'status' => AssignmentStatus::Acknowledged->value,
        'responded_at' => $acknowledgedAt,
    ]);

    app(LocationBackfill::class)->run(apply: true);

    $rows = DB::table('assignments')
        ->where('assignable_type', 'proposal_location')
        ->where('field', 'responsible_employee_id')
        ->get()
        ->keyBy('assignable_id');

    expect($rows)->toHaveCount(2)
        ->and($rows[$later->id]->status)->toBe(AssignmentStatus::Acknowledged->value)
        ->and($rows[$earlier->id]->status)->toBe(AssignmentStatus::Acknowledged->value)
        ->and(CarbonImmutable::parse($rows[$earlier->id]->responded_at)->toDateTimeString())->toBe($acknowledgedAt->toDateTimeString());
});

test('a pending project assignment is mirrored as pending, not acknowledged', function () {
    $project = backfillProject();
    $row = backfillRow($project, '2026-01-01', '2026-12-31');

    Assignment::query()->where('assignable_type', 'project')->where('assignable_id', $project->id)->delete();
    Assignment::factory()->create([
        'assignable_type' => 'project',
        'assignable_id' => $project->id,
        'field' => 'user_id',
        'employee_id' => $project->user_id,
        'status' => AssignmentStatus::Pending->value,
    ]);

    app(LocationBackfill::class)->run(apply: true);

    expect(DB::table('assignments')->where('assignable_type', 'proposal_location')->where('assignable_id', $row->id)->value('status'))
        ->toBe(AssignmentStatus::Pending->value);
});

test('a row with no project keeps draft and null dates', function () {
    $row = ProposalLocation::factory()->create();

    app(LocationBackfill::class)->run(apply: true);

    $stored = DB::table('proposal_locations')->find($row->id);

    expect($stored->status)->toBe(ProjectStatus::Draft->value)
        ->and($stored->start_date)->toBeNull()
        ->and($stored->end_date)->toBeNull();
});

test('a project no row carries is listed and does not stop the run', function () {
    $rowless = backfillProject();
    $carried = backfillProject();
    backfillRow($carried, '2026-01-01', '2026-12-31');

    $counts = app(LocationBackfill::class)->run(apply: true);

    expect($counts['projects_without_row'])->toBe([$rowless->id])
        ->and($counts['rows_filled'])->toBe(1);
});

test('the dry run writes nothing and prints the same counts', function () {
    $project = backfillProject();
    $row = backfillRow($project, '2026-01-01', '2026-12-31');
    Media::factory()->create(['mediable_type' => 'project', 'mediable_id' => $project->id, 'created_at' => '2026-06-01']);

    $dry = app(LocationBackfill::class)->run(apply: false);

    expect(DB::table('proposal_locations')->find($row->id)->status)->toBe(ProjectStatus::Draft->value)
        ->and(DB::table('media')->where('mediable_type', 'proposal_location')->count())->toBe(0);

    $applied = app(LocationBackfill::class)->run(apply: true);

    expect($applied)->toEqual($dry);
});

test('a second run writes nothing more', function () {
    $project = backfillProject();
    backfillRow($project, '2026-01-01', '2026-12-31');

    app(LocationBackfill::class)->run(apply: true);
    $second = app(LocationBackfill::class)->run(apply: true);

    expect($second['rows_filled'])->toBe(0)
        ->and($second['files_mirrored'])->toBe(0)
        ->and($second['acknowledgements_copied'])->toBe(0)
        ->and($second['sectors_copied'])->toBe(0);
});

test('slugs are unique when two rows of one proposal share a city and program', function () {
    $project = backfillProject();
    $first = backfillRow($project, '2026-01-01', '2026-12-31');
    $second = backfillRow($project, '2025-01-01', '2025-12-31');

    app(LocationBackfill::class)->run(apply: true);

    $slugs = DB::table('proposal_locations')->whereIn('id', [$first->id, $second->id])->pluck('slug')->all();

    expect($slugs)->toHaveCount(2)
        ->and(array_unique($slugs))->toHaveCount(2);
});

test('a rowless project blocks the second migration and names its id', function () {
    $rowless = backfillProject();
    $carried = backfillProject();
    backfillRow($carried, '2026-01-01', '2026-12-31');

    $migration = require base_path('database/migrations/2026_11_20_122855_add_unique_slug_to_proposal_locations_table.php');

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, (string) $rowless->id);
});

test('the second migration stops before changing anything when it refuses', function () {
    backfillProject();

    $migration = require base_path('database/migrations/2026_11_20_122855_add_unique_slug_to_proposal_locations_table.php');

    try {
        $migration->up();
    } catch (RuntimeException) {
        // expected
    }

    // The dry run the pre-check runs must leave no trace of its own.
    expect(DB::table('proposal_locations')->whereNotNull('slug')->count())->toBe(0);
});

test('the counts separate rows decided by date from rows decided by most recent', function () {
    $project = backfillProject();
    backfillRow($project, '2024-01-01', '2024-12-31');
    backfillRow($project, '2026-01-01', '2026-12-31');
    Media::factory()->create(['mediable_type' => 'project', 'mediable_id' => $project->id, 'created_at' => '2026-06-01']);
    Media::factory()->create(['mediable_type' => 'project', 'mediable_id' => $project->id, 'created_at' => '2030-06-01']);

    $counts = app(LocationBackfill::class)->run(apply: true);

    expect($counts['files_by_date'])->toBe(1)
        ->and($counts['files_by_most_recent'])->toBe(1)
        ->and($counts['projects_on_several_rows'])->toBe([$project->id]);
});
