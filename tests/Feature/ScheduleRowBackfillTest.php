<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\Proposal;
use App\Models\ScheduleBaseline;
use App\Models\ScheduleDependency;
use App\Models\Task;
use App\Support\Locations\ScheduleRowBackfill;
use Illuminate\Support\Facades\DB;

/**
 * Y-12 (D-93, D-94): a dependency follows its successor's row, a pair split
 * across two rows is deleted, and a baseline takes the row its own date picks.
 *
 * Both tables are empty on local and production data, so these fixtures are
 * the only proof the rules work.
 */

/**
 * A project with two dated proposals, each carrying it through one row.
 *
 * @return array{0: Project, 1: int, 2: int} project, earlier row id, later row id
 */
function scheduleProject(): array
{
    $project = Project::factory()->create();

    $first = Proposal::factory()->create(['actual_date_begin' => '2025-01-01', 'actual_date_end' => '2025-12-31']);
    $first->projects()->attach($project->id);
    $earlier = proposalRowForProject($first, $project, false);

    $second = Proposal::factory()->create(['actual_date_begin' => '2026-01-01', 'actual_date_end' => '2026-12-31']);
    $second->projects()->attach($project->id);
    $later = proposalRowForProject($second, $project, false);

    return [$project, $earlier->id, $later->id];
}

/**
 * A task already sitting on a location row.
 */
function rowTask(int $rowId, ?string $dueDate = '2026-06-01'): Task
{
    return Task::factory()->create([
        'source_type' => 'proposal_location',
        'source_id' => $rowId,
        'due_date' => $dueDate,
    ]);
}

test('a dependency takes the row of its successor', function () {
    [$project, , $later] = scheduleProject();
    $predecessor = rowTask($later);
    $successor = rowTask($later);

    $dependency = ScheduleDependency::factory()->create([
        'project_id' => $project->id,
        'proposal_location_id' => null,
        'predecessor_type' => 'task',
        'predecessor_id' => $predecessor->id,
        'successor_type' => 'task',
        'successor_id' => $successor->id,
    ]);

    $counts = app(ScheduleRowBackfill::class)->run(apply: true);

    expect((int) DB::table('schedule_dependencies')->find($dependency->id)->proposal_location_id)->toBe($later)
        ->and($counts['dependencies_moved'])->toBe(1)
        ->and($counts['dependencies_deleted'])->toBe([])
        // project_id is kept and still written until Y-18.
        ->and((int) DB::table('schedule_dependencies')->find($dependency->id)->project_id)->toBe($project->id);
});

test('a dependency whose ends land on different rows is deleted and listed', function () {
    [$project, $earlier, $later] = scheduleProject();
    $predecessor = rowTask($earlier);
    $successor = rowTask($later);

    $dependency = ScheduleDependency::factory()->create([
        'project_id' => $project->id,
        'proposal_location_id' => null,
        'predecessor_type' => 'task',
        'predecessor_id' => $predecessor->id,
        'successor_type' => 'task',
        'successor_id' => $successor->id,
    ]);

    $counts = app(ScheduleRowBackfill::class)->run(apply: true);

    expect(DB::table('schedule_dependencies')->find($dependency->id))->toBeNull()
        ->and($counts['dependencies_deleted'])->toBe([$dependency->id])
        ->and($counts['dependencies_moved'])->toBe(0);
});

test('a dependency with an end that is not a task is listed and left alone', function () {
    [$project, , $later] = scheduleProject();
    $successor = rowTask($later);

    $dependency = ScheduleDependency::factory()->create([
        'project_id' => $project->id,
        'proposal_location_id' => null,
        // The dead `milestone` type: its table was dropped on 17 Nov.
        'predecessor_type' => 'milestone',
        'predecessor_id' => 999,
        'successor_type' => 'task',
        'successor_id' => $successor->id,
    ]);

    $counts = app(ScheduleRowBackfill::class)->run(apply: true);

    expect(DB::table('schedule_dependencies')->find($dependency->id)->proposal_location_id)->toBeNull()
        ->and($counts['dependencies_unresolved'])->toBe([$dependency->id])
        ->and($counts['dependencies_deleted'])->toBe([]);
});

test('a baseline takes the row its own date picks', function () {
    [$project, $earlier, $later] = scheduleProject();

    $old = ScheduleBaseline::factory()->create(['project_id' => $project->id, 'proposal_location_id' => null]);
    DB::table('schedule_baselines')->where('id', $old->id)->update(['created_at' => '2025-06-01 09:00:00']);

    $new = ScheduleBaseline::factory()->create(['project_id' => $project->id, 'proposal_location_id' => null]);
    DB::table('schedule_baselines')->where('id', $new->id)->update(['created_at' => '2026-06-01 09:00:00']);

    $counts = app(ScheduleRowBackfill::class)->run(apply: true);

    expect((int) DB::table('schedule_baselines')->find($old->id)->proposal_location_id)->toBe($earlier)
        ->and((int) DB::table('schedule_baselines')->find($new->id)->proposal_location_id)->toBe($later)
        ->and($counts['baselines_moved'])->toBe(2)
        ->and($counts['baselines_by_date'])->toBe(2);
});

test('a baseline item whose task is on another row is dropped and counted', function () {
    [$project, $earlier, $later] = scheduleProject();

    $baseline = ScheduleBaseline::factory()->create(['project_id' => $project->id, 'proposal_location_id' => null]);
    DB::table('schedule_baselines')->where('id', $baseline->id)->update(['created_at' => '2026-06-01 09:00:00']);

    $onRow = rowTask($later);
    $elsewhere = rowTask($earlier);

    foreach ([$onRow, $elsewhere] as $task) {
        DB::table('schedule_baseline_items')->insert([
            'schedule_baseline_id' => $baseline->id,
            'item_type' => 'task',
            'item_id' => $task->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $counts = app(ScheduleRowBackfill::class)->run(apply: true);

    $kept = DB::table('schedule_baseline_items')->where('schedule_baseline_id', $baseline->id)->pluck('item_id')->all();

    expect($counts['baseline_items_dropped'])->toBe(1)
        ->and(array_map(intval(...), $kept))->toBe([$onRow->id]);
});

test('the dry run writes nothing and reports the same counts', function () {
    [$project, $earlier, $later] = scheduleProject();
    $dependency = ScheduleDependency::factory()->create([
        'project_id' => $project->id,
        'proposal_location_id' => null,
        'predecessor_type' => 'task',
        'predecessor_id' => rowTask($earlier)->id,
        'successor_type' => 'task',
        'successor_id' => rowTask($later)->id,
    ]);

    $dry = app(ScheduleRowBackfill::class)->run(apply: false);

    expect(DB::table('schedule_dependencies')->find($dependency->id))->not->toBeNull();

    $applied = app(ScheduleRowBackfill::class)->run(apply: true);

    expect($applied)->toEqual($dry)
        ->and(DB::table('schedule_dependencies')->find($dependency->id))->toBeNull();
});

test('a second run writes nothing more', function () {
    [$project, , $later] = scheduleProject();
    ScheduleDependency::factory()->create([
        'project_id' => $project->id,
        'proposal_location_id' => null,
        'predecessor_type' => 'task',
        'predecessor_id' => rowTask($later)->id,
        'successor_type' => 'task',
        'successor_id' => rowTask($later)->id,
    ]);

    app(ScheduleRowBackfill::class)->run(apply: true);
    $second = app(ScheduleRowBackfill::class)->run(apply: true);

    expect($second['dependencies'])->toBe(0)
        ->and($second['baselines'])->toBe(0);
});
