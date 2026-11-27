<?php

declare(strict_types=1);

use App\Models\ActivityPlan;
use App\Models\ActivityPlanFollowUp;
use App\Models\ActivityPlanItem;
use App\Models\PlanUpdate;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Task;
use App\Support\Locations\PlanUpdateRowBackfill;
use Illuminate\Support\Facades\DB;

/**
 * Y-15 slice 3 (D-93): a plan's monthly update and the follow-up task it
 * fired move from their project to the location row of the plan's proposal
 * that carries that project. No date rule is needed.
 */

/**
 * A monthly update in the legacy shape: keyed by project only.
 */
function legacyPlanUpdate(ActivityPlan $plan, Project $project): int
{
    // The row is nulled on purpose: this is the shape the backfill is
    // asked to move, and since Y-18 the factory places an update itself.
    return PlanUpdate::factory()->create([
        'activity_plan_id' => $plan->id,
        'project_id' => $project->id,
        'proposal_location_id' => null,
    ])->id;
}

/**
 * A follow-up task in the legacy shape: keyed by project only.
 */
function legacyFollowUpTask(ActivityPlan $plan, Project $project): int
{
    $followUp = ActivityPlanFollowUp::factory()->create([
        'activity_plan_item_id' => ActivityPlanItem::factory()->create(['activity_plan_id' => $plan->id])->id,
    ]);

    return (int) DB::table('activity_plan_follow_up_tasks')->insertGetId([
        'activity_plan_follow_up_id' => $followUp->id,
        'project_id' => $project->id,
        'task_id' => Task::factory()->create()->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function backfillPlanUpdates(bool $apply = true): array
{
    return app(PlanUpdateRowBackfill::class)->run($apply);
}

function storedPlanRow(string $table, int $id): ?int
{
    $value = DB::table($table)->where('id', $id)->value('proposal_location_id');

    return $value === null ? null : (int) $value;
}

test('an update and a follow-up task go to the row of the plan\'s proposal even when another proposal carries the same project', function () {
    $project = Project::factory()->create();
    $own = Proposal::factory()->create();
    $ownRow = proposalRowForProject($own, $project, false);
    proposalRowForProject(Proposal::factory()->create(), $project, false);
    $plan = ActivityPlan::factory()->approved()->create(['proposal_id' => $own->id, 'program_id' => $project->program_id]);
    $update = legacyPlanUpdate($plan, $project);
    $task = legacyFollowUpTask($plan, $project);

    $counts = backfillPlanUpdates();

    expect(storedPlanRow('plan_updates', $update))->toBe($ownRow->id)
        ->and(storedPlanRow('activity_plan_follow_up_tasks', $task))->toBe($ownRow->id)
        ->and((int) DB::table('plan_updates')->where('id', $update)->value('project_id'))->toBe($project->id)
        ->and($counts)->toBe([
            'updates' => ['seen' => 1, 'moved' => 1, 'unmapped' => []],
            'follow_up_tasks' => ['seen' => 1, 'moved' => 1, 'unmapped' => []],
            'by_own_proposal' => 2,
            'by_date' => 0,
            'by_most_recent' => 0,
            'rows' => [$ownRow->id => 2],
        ]);
});

test('a row whose project no row of the plan\'s proposal carries is listed, left alone, and blocks the tightening', function () {
    $project = Project::factory()->create();
    proposalRowForProject(Proposal::factory()->create(), $project, false);
    $plan = ActivityPlan::factory()->approved()->create(['program_id' => $project->program_id]);
    $update = legacyPlanUpdate($plan, $project);

    $counts = backfillPlanUpdates();

    expect(storedPlanRow('plan_updates', $update))->toBeNull()
        ->and($counts['updates']['unmapped'])->toBe([$update]);

    $migration = require database_path('migrations/2026_11_24_100002_key_plan_update_tables_on_location_row.php');

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'plan_updates: '.$update);
});

test('the dry run reports the same counts and writes nothing', function () {
    $project = Project::factory()->create();
    $proposal = Proposal::factory()->create();
    $row = proposalRowForProject($proposal, $project, false);
    $update = legacyPlanUpdate(ActivityPlan::factory()->approved()->create(['proposal_id' => $proposal->id, 'program_id' => $project->program_id]), $project);

    $counts = backfillPlanUpdates(apply: false);

    expect($counts['updates']['moved'])->toBe(1)
        ->and($counts['rows'])->toBe([$row->id => 1])
        ->and(storedPlanRow('plan_updates', $update))->toBeNull();
});

test('the command prints its counts, writes only when applied, and is safe to run twice', function () {
    $project = Project::factory()->create();
    $proposal = Proposal::factory()->create();
    $row = proposalRowForProject($proposal, $project, false);
    $update = legacyPlanUpdate(ActivityPlan::factory()->approved()->create(['proposal_id' => $proposal->id, 'program_id' => $project->program_id]), $project);

    $this->artisan('plan-updates:row-backfill', ['--dry-run' => true])
        ->expectsOutputToContain(__('annual_plans.console.row_backfill_dry_run'))
        ->expectsOutputToContain(__('annual_plans.console.row_backfill_unmapped_updates', ['ids' => __('annual_plans.console.row_backfill_none')]))
        ->assertSuccessful();

    expect(storedPlanRow('plan_updates', $update))->toBeNull();

    $this->artisan('plan-updates:row-backfill')->assertSuccessful();
    $this->artisan('plan-updates:row-backfill')->assertSuccessful();

    expect(storedPlanRow('plan_updates', $update))->toBe($row->id)
        ->and(backfillPlanUpdates()['updates']['seen'])->toBe(0);
});
