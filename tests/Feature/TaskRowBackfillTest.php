<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\Task;
use App\Support\Locations\TaskRowBackfill;
use Illuminate\Support\Facades\DB;

/**
 * Y-12 (D-93, D-94): a task raised against a project moves to the location
 * row its own date picks. The project's id is left on nothing — the source
 * becomes the row — and a task whose project no row carries is reported and
 * left alone (D-80).
 */

/**
 * A project carried by one city-wide row of its own dated proposal.
 *
 * @return array{0: Project, 1: ProposalLocation}
 */
function taskBackfillProject(string $begin = '2026-01-01', string $end = '2026-12-31'): array
{
    $project = Project::factory()->create();
    $proposal = Proposal::factory()->create(['actual_date_begin' => $begin, 'actual_date_end' => $end]);
    $proposal->projects()->attach($project->id);

    return [$project, proposalRowForProject($proposal, $project, false)];
}

/**
 * A task raised straight against a project.
 */
function backfillProjectTask(Project $project, ?string $dueDate, ?string $createdAt = null): Task
{
    $task = Task::factory()->create([
        'source_type' => 'project',
        'source_id' => $project->id,
        'due_date' => $dueDate,
    ]);

    if ($createdAt !== null) {
        DB::table('tasks')->where('id', $task->id)->update(['created_at' => $createdAt]);
    }

    return $task->fresh();
}

test('a project task moves to the row carrying its project', function () {
    [$project, $row] = taskBackfillProject();
    $task = backfillProjectTask($project, '2026-06-01');

    $counts = app(TaskRowBackfill::class)->run(apply: true);

    $stored = DB::table('tasks')->find($task->id);

    expect($stored->source_type)->toBe('proposal_location')
        ->and((int) $stored->source_id)->toBe($row->id)
        ->and($counts['moved'])->toBe(1)
        ->and($counts['unmapped'])->toBe([]);
});

test('the due date picks the row when two proposals carry the project', function () {
    $project = Project::factory()->create();

    $first = Proposal::factory()->create(['actual_date_begin' => '2025-01-01', 'actual_date_end' => '2025-12-31']);
    $first->projects()->attach($project->id);
    $earlierRow = proposalRowForProject($first, $project, false);

    $second = Proposal::factory()->create(['actual_date_begin' => '2026-01-01', 'actual_date_end' => '2026-12-31']);
    $second->projects()->attach($project->id);
    $laterRow = proposalRowForProject($second, $project, false);

    $old = backfillProjectTask($project, '2025-06-01');
    $new = backfillProjectTask($project, '2026-06-01');

    $counts = app(TaskRowBackfill::class)->run(apply: true);

    expect((int) DB::table('tasks')->find($old->id)->source_id)->toBe($earlierRow->id)
        ->and((int) DB::table('tasks')->find($new->id)->source_id)->toBe($laterRow->id)
        ->and($counts['by_date'])->toBe(2)
        ->and($counts['by_most_recent'])->toBe(0);
});

test('a task no period covers falls to the row of the most recent proposal', function () {
    $project = Project::factory()->create();

    $first = Proposal::factory()->create(['actual_date_begin' => '2025-01-01', 'actual_date_end' => '2025-12-31']);
    $first->projects()->attach($project->id);
    proposalRowForProject($first, $project, false);

    $second = Proposal::factory()->create(['actual_date_begin' => '2026-01-01', 'actual_date_end' => '2026-12-31']);
    $second->projects()->attach($project->id);
    $laterRow = proposalRowForProject($second, $project, false);

    $task = backfillProjectTask($project, '2030-06-01');

    $counts = app(TaskRowBackfill::class)->run(apply: true);

    expect((int) DB::table('tasks')->find($task->id)->source_id)->toBe($laterRow->id)
        ->and($counts['by_date'])->toBe(0)
        ->and($counts['by_most_recent'])->toBe(1);
});

test('a task with no due date is placed by the day it was recorded', function () {
    $project = Project::factory()->create();

    $first = Proposal::factory()->create(['actual_date_begin' => '2025-01-01', 'actual_date_end' => '2025-12-31']);
    $first->projects()->attach($project->id);
    $earlierRow = proposalRowForProject($first, $project, false);

    $second = Proposal::factory()->create(['actual_date_begin' => '2026-01-01', 'actual_date_end' => '2026-12-31']);
    $second->projects()->attach($project->id);
    proposalRowForProject($second, $project, false);

    $task = backfillProjectTask($project, null, '2025-07-15 09:00:00');

    app(TaskRowBackfill::class)->run(apply: true);

    expect((int) DB::table('tasks')->find($task->id)->source_id)->toBe($earlierRow->id);
});

test('a subtask follows its parent whatever its own date', function () {
    $project = Project::factory()->create();

    $first = Proposal::factory()->create(['actual_date_begin' => '2025-01-01', 'actual_date_end' => '2025-12-31']);
    $first->projects()->attach($project->id);
    $earlierRow = proposalRowForProject($first, $project, false);

    $second = Proposal::factory()->create(['actual_date_begin' => '2026-01-01', 'actual_date_end' => '2026-12-31']);
    $second->projects()->attach($project->id);
    proposalRowForProject($second, $project, false);

    $parent = backfillProjectTask($project, '2025-06-01');
    $child = Task::factory()->create([
        'source_type' => 'project',
        'source_id' => $project->id,
        'parent_task_id' => $parent->id,
        'due_date' => '2026-06-01',
    ]);

    $counts = app(TaskRowBackfill::class)->run(apply: true);

    // By its own date the child would land on the later row; it follows its
    // parent instead, so a task tree is never split across rows.
    expect((int) DB::table('tasks')->find($child->id)->source_id)->toBe($earlierRow->id)
        ->and((int) DB::table('tasks')->find($parent->id)->source_id)->toBe($earlierRow->id)
        ->and($counts['subtasks_followed'])->toBe(1);
});

test('a task whose project no row carries is listed and left alone', function () {
    $rowless = Project::factory()->create();
    $task = backfillProjectTask($rowless, '2026-06-01');

    [$carried] = taskBackfillProject();
    backfillProjectTask($carried, '2026-06-01');

    $counts = app(TaskRowBackfill::class)->run(apply: true);

    $stored = DB::table('tasks')->find($task->id);

    expect($stored->source_type)->toBe('project')
        ->and((int) $stored->source_id)->toBe($rowless->id)
        ->and($counts['unmapped'])->toBe([$task->id])
        ->and($counts['moved'])->toBe(1);
});

test('tasks of other sources are untouched', function () {
    $proposal = Proposal::factory()->create();
    $task = Task::factory()->create(['source_type' => 'proposal', 'source_id' => $proposal->id]);

    $counts = app(TaskRowBackfill::class)->run(apply: true);

    expect(DB::table('tasks')->find($task->id)->source_type)->toBe('proposal')
        ->and($counts['tasks'])->toBe(0);
});

test('the dry run writes nothing and reports the same counts', function () {
    [$project] = taskBackfillProject();
    $task = backfillProjectTask($project, '2026-06-01');

    $dry = app(TaskRowBackfill::class)->run(apply: false);

    expect(DB::table('tasks')->find($task->id)->source_type)->toBe('project');

    $applied = app(TaskRowBackfill::class)->run(apply: true);

    expect($applied)->toEqual($dry);
});

test('a second run writes nothing more', function () {
    [$project] = taskBackfillProject();
    backfillProjectTask($project, '2026-06-01');

    app(TaskRowBackfill::class)->run(apply: true);
    $second = app(TaskRowBackfill::class)->run(apply: true);

    expect($second['tasks'])->toBe(0)
        ->and($second['moved'])->toBe(0);
});
